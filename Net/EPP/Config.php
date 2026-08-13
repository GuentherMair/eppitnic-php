<?php

namespace Net\EPP;

use Hexmode\IOMode\IOMode;
use RedBeanPHP\R;

/**
 * Owns the database connection (formerly helpers/db.php, `files`-autoloaded
 * eagerly on every request/script -- now established lazily here instead,
 * the first time anything actually needs it) and loads every row of the
 * `settings` table once, serving values from that in-memory cache for the
 * rest of the request.
 *
 * Also owns schema versioning: right after connecting, it compares the
 * `settings` table's 'schema_version' row (zero-padded MMmmrr, e.g.
 * '070000' for 7.0.0 -- see SCHEMA_VERSION, config/constants.php) against
 * SCHEMA_VERSION and applies any outstanding
 * config/mariadb-schema-upgrade-{from}-to-{to}.sql files, walking the chain
 * one version-to-next-version step at a time (never a single file spanning
 * several versions -- see findMigration()) until it catches up. If the
 * `settings` table doesn't exist at all yet, there's no stamp to read, so
 * '060700' -- the legacy pre-versioning baseline -- is assumed as the
 * starting point and fed into that same lookup, currently resolving to
 * config/mariadb-schema-upgrade-060700-to-070000.sql (which also creates
 * `settings` as part of what it applies). No filename is special-cased.
 *
 * Because the database connection is lazy now rather than eager, anything
 * that touches `R::` directly -- not through a Net\EPP object, whose
 * constructor already goes through Config::get() -- must trigger this class
 * first, even if it doesn't need any particular setting value: call
 * Config::init().
 *
 * If config/config.php doesn't exist yet, connect() (via loadConfig())
 * either walks the user through creating it interactively -- when attached
 * to a real terminal, see isInteractive() -- or fails with a clear
 * error otherwise (e.g. a web request, or a non-interactive cron/CI run).
 *
 * Similarly, config/mariadb-schema.sql and the upgrade script seed the
 * `settings` table with placeholder values for a handful of settings that
 * can't have a real default (jwt_psk, allowed_origins, epp credentials) --
 * setupSettings() fills those in the first time the constructor runs.
 */
final class Config
{
    private static ?self $instance = null;
    private static ?string $configFile = null;
    private array $settings = [];

    /**
     * connect to the database, run any outstanding migrations, load every
     * setting into the in-process cache, and fill in whichever
     * security-/identity-critical settings are still at their schema-default
     * placeholder -- see connect(), migrate(), setupSettings()
     */
    private function __construct() {
        self::connect();
        self::migrate();

        foreach (R::getAll('SELECT `key`, `value` FROM settings') as $row) {
            $this->settings[$row['key']] = json_decode($row['value'], true);
        }

        $this->setupSettings();
    }

    /**
     * @return self the lazily-created singleton instance
     */
    private static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Whether this process is attached to a real interactive terminal on
     * STDIN. Checks PHP_SAPI first: IOMode::isTTY() references the STDIN
     * constant unconditionally, which isn't even defined outside the `cli`
     * SAPI (e.g. `cli-server`, fpm-fcgi) -- calling it there would fatal,
     * not just return false.
     *
     * @return bool status
     */
    private static function isInteractive(): bool {
        return PHP_SAPI === 'cli' && IOMode::isTTY();
    }

    /**
     * Establish the database connection and run any outstanding migrations,
     * without needing an actual setting value. For scripts that touch `R::`
     * directly but never call get()/set() themselves.
     */
    public static function init(): void {
        self::instance();
    }

    /**
     * Install a fully-formed settings array as the singleton's state, without
     * touching the database at all -- no connect(), no migrate(), no
     * setupSettings() (which would prompt, or auto-generate a jwt_psk).
     *
     * Exists for the test suite: nearly everything in this codebase reaches
     * Config through Client's constructor, so without this hook every unit
     * test would need a live MariaDB with a migrated schema, and CI would need
     * one too. Production code must not call it -- get()/set() would then be
     * answering from a cache no database ever backed, and set() would still
     * try to REPLACE INTO a table it never connected to.
     *
     * @param array $settings the complete settings map, as get() should answer it
     */
    public static function loadForTesting(array $settings): void {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->settings = $settings;
        self::$instance = $instance;
    }

    /**
     * Drop the singleton, so the next call rebuilds it (from the database, or
     * from whatever loadForTesting() installs next). Test-suite teardown only.
     */
    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * @param string $key setting name
     * @return mixed the setting's value
     * @throws \RuntimeException if $key was never seeded
     */
    public static function get(string $key): mixed {
        $settings = self::instance()->settings;
        if ( ! array_key_exists($key, $settings)) {
            throw new \RuntimeException("Config key '{$key}' not found");
        }
        return $settings[$key];
    }

    /**
     * persist + keep the in-process cache consistent (used by EPP password rotation)
     *
     * @param string $key setting name
     * @param mixed $value new value
     */
    public static function set(string $key, mixed $value): void {
        // instance() first -- ensures connect()/migrate() have already run
        // (and so the `settings` table actually exists) before writing to it
        // directly. Matters when set() is the very first Config call in a
        // process, e.g. `eppitnic config migrate` seeding a freshly-migrated schema.
        $instance = self::instance();
        R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', [$key, json_encode($value)]);
        $instance->settings[$key] = $value;
    }

    /**
     * Connect to the database: load config/config.php (see loadConfig()),
     * validate the DB_* constants it's expected to define, alias
     * RedBeanPHP's global (non-namespaced) `R` facade -- not pulled in by
     * composer's autoload for this package, so bare `R::...` call sites
     * elsewhere (routes/*.php) need it defined somewhere -- and
     * connect/freeze it. A no-op after the first call (R::hasDatabase()
     * guards it).
     *
     * @throws \RuntimeException if config/config.php is missing/incomplete,
     *                            or the database connection itself fails
     */
    private static function connect(): void {
        self::loadConfig();

        $missing = array_filter(
            ['DB_TYPE', 'DB_HOST', 'DB_NAME', 'DB_CHARSET', 'DB_USER', 'DB_PASSWORD'],
            fn($name) => ! defined($name)
        );
        if ( ! empty($missing)) {
            throw new \RuntimeException(
                'Database configuration incomplete in config/config.php: missing ' . implode(', ', $missing)
            );
        }

        if ( ! class_exists('R', false)) {
            class_alias(R::class, 'R');
        }

        if ( ! R::hasDatabase('default')) {
            try {
                R::setup(DB_TYPE.':host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASSWORD);
                // R::setup() only configures the DSN -- RedBeanPHP connects
                // lazily, on the first real query. Force that connection
                // attempt now, inside this try block, so a failure surfaces
                // here with a clear message instead of later (e.g. from
                // migrate()'s first query, deep in the constructor).
                R::getCell('SELECT 1');
                R::freeze(true);
                R::getWriter()->setUseCache(true);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Unable to connect to the database: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Ensure config/config.php is loaded: require it if it already exists.
     * Otherwise, if this process is attached to a real interactive terminal
     * (isInteractive()), walk the user through creating it
     * (setupConfig()); if not, there's nobody to ask, so fail loudly.
     *
     * @throws \RuntimeException if config/config.php is missing and this
     *                            process isn't interactive
     */
    private static function loadConfig(): void {
        self::$configFile ??= dirname(__FILE__) . '/../../config/config.php';

        if (is_readable(self::$configFile)) {
            require_once self::$configFile;
            return;
        }

        if (self::isInteractive()) {
            self::setupConfig();
            return;
        }

        throw new \RuntimeException(
            "Database configuration missing: '" . self::$configFile . "' not found or not readable. " .
            "Copy config/config.php-template to config/config.php and fill in your database credentials."
        );
    }

    /**
     * Interactively prompt for database credentials and write them to
     * self::$configFile. Only ever reached from loadConfig(), which has
     * already confirmed we're attached to a real terminal.
     *
     * @throws \RuntimeException if self::$configFile can't be written
     */
    private static function setupConfig(): void {
        fwrite(STDOUT, "No database configuration found at '" . self::$configFile . "'.\n");
        fwrite(STDOUT, "Let's set one up now (press enter to accept the default in [brackets]).\n\n");

        $dbType     = self::prompt('Database type', 'mysql');
        $dbHost     = self::prompt('Database host', 'localhost');
        $dbName     = self::prompt('Database name', 'eppitnic');
        $dbCharset  = self::prompt('Database charset', 'utf8');
        $dbUser     = self::prompt('Database user', 'eppitnic');
        $dbPassword = self::prompt('Database password');

        $php = "<?php\n\n"
            . "define('DB_TYPE',     " . var_export($dbType, true) . ");\n"
            . "define('DB_HOST',     " . var_export($dbHost, true) . ");\n"
            . "define('DB_NAME',     " . var_export($dbName, true) . ");\n"
            . "define('DB_CHARSET',  " . var_export($dbCharset, true) . ");\n"
            . "define('DB_USER',     " . var_export($dbUser, true) . ");\n"
            . "define('DB_PASSWORD', " . var_export($dbPassword, true) . ");\n";

        if (file_put_contents(self::$configFile, $php) === false) {
            throw new \RuntimeException("Unable to write '" . self::$configFile . "'.");
        }
        fwrite(STDOUT, "\nWrote '" . self::$configFile . "'.\n\n");

        require_once self::$configFile;
    }

    /**
     * @param string $label prompt text
     * @param string $default value used if the user just presses enter
     * @return string the entered value, or $default if left blank
     */
    private static function prompt(string $label, string $default = ''): string {
        $suffix = $default !== '' ? " [{$default}]" : '';
        fwrite(STDOUT, "{$label}{$suffix}: ");
        $line = trim((string) fgets(STDIN));
        return $line !== '' ? $line : $default;
    }

    /**
     * like prompt(), but best-effort hides the typed characters (via `stty
     * -echo`, when available -- not on Windows, or if `stty` isn't on
     * PATH -- falling back to a plain visible prompt otherwise)
     *
     * @param string $label prompt text
     * @return string the entered value
     */
    private static function promptHidden(string $label): string {
        $canHide = PHP_OS_FAMILY !== 'Windows' && trim((string) @shell_exec('command -v stty')) !== '';
        if ( ! $canHide) {
            return self::prompt($label);
        }

        fwrite(STDOUT, "{$label}: ");
        shell_exec('stty -echo');
        try {
            $line = trim((string) fgets(STDIN));
        } finally {
            shell_exec('stty echo');
        }
        fwrite(STDOUT, "\n");
        return $line;
    }

    /**
     * If any of a handful of security-/identity-critical settings are still
     * at their schema-default placeholder value, fill them in. jwt_psk is
     * always silently regenerated -- it's a real secret, not something a
     * human should type in -- regardless of interactivity. The rest
     * (allowed_origins, epp.username/password/cl_trid_prefix) genuinely
     * need a human, so they're only prompted for when attached to a real
     * terminal (isInteractive()); a non-interactive first run (cron,
     * CI, a container's entrypoint) still boots, just with CORS/EPP left
     * unusable until configured by hand. Runs once, from the constructor,
     * right after settings are first loaded.
     */
    private function setupSettings(): void {
        if ($this->settings['jwt_psk'] === '') {
            $this->persistSetting('jwt_psk', base64_encode(random_bytes(32)));
        }

        if ( ! self::isInteractive()) {
            return;
        }

        if (empty(array_filter($this->settings['allowed_origins']))) {
            fwrite(STDOUT, "\nNo CORS origins are configured yet -- the API will refuse every browser request until this is set.\n");
            $origins = self::prompt('Allowed origins, comma-separated (e.g. https://app.example.com)');
            $origins = array_values(array_filter(array_map('trim', explode(',', $origins))));
            if ( ! empty($origins)) {
                $this->persistSetting('allowed_origins', $origins);
            }
        }

        $epp = $this->settings['epp'];
        if (($epp['username'] ?? '') === '') {
            fwrite(STDOUT, "\nNo EPP credentials are configured yet -- domain/contact operations won't work until this is set.\n");
            $epp['username'] = self::prompt('EPP username');
            $epp['password'] = self::promptHidden('EPP password');
            $epp['cl_trid_prefix'] = self::prompt('EPP clTRID prefix', strtok($epp['username'], '-') ?: $epp['username']);
            $this->persistSetting('epp', $epp);
        }
    }

    /**
     * Write directly to both the database and the in-process cache,
     * bypassing set()'s self::instance() call -- safe to use from setupSettings(),
     * itself called from inside the constructor, unlike the public set(),
     * which would recurse back into the constructor here.
     *
     * @param string $key setting name
     * @param mixed $value new value
     */
    private function persistSetting(string $key, mixed $value): void {
        R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', [$key, json_encode($value)]);
        $this->settings[$key] = $value;
    }

    /**
     * Bring the `settings` table -- and, transitively, the whole schema --
     * up to SCHEMA_VERSION, applying config/mariadb-schema-upgrade-*.sql
     * files in sequence. Deliberately talks to `R::` directly rather than
     * through get()/set(): this runs from inside the constructor, before
     * self::$instance is assigned, so calling back into self::instance()
     * here would recurse.
     */
    private static function migrate(): void {
        if (empty(R::getAll("SHOW TABLES LIKE 'settings'"))) {
            // no `settings` table, so no stamp to read -- assume the legacy
            // pre-versioning baseline and let the loop below find its own
            // way from there via the normal filename lookup, same as any
            // other step in the chain (no filename hardcoded here)
            $current = '060700';
        } else {
            $current = R::getCell("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
            if ($current === null || $current === false) {
                // `settings` existed already but predates this versioning feature --
                // its shape already matches SCHEMA_VERSION, just stamp it
                R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', ['schema_version', json_encode(SCHEMA_VERSION)]);
                return;
            }
            $current = json_decode($current, true);
        }

        // Migrations only ever run forwards, so a database stamped newer than
        // this checkout has nowhere to go. Say that, rather than letting the
        // loop look for a migration away from a version it has never heard of
        // and report the missing file as if it were the problem.
        if ((int) $current > (int) SCHEMA_VERSION) {
            throw new \RuntimeException(
                "The database schema is version '{$current}', newer than this installation's '" . SCHEMA_VERSION . "'. " .
                "Update the code to a release that knows this schema -- migrations do not run backwards."
            );
        }

        while ($current !== SCHEMA_VERSION) {
            $next = self::findMigration($current);
            if ($next === null) {
                throw new \RuntimeException(
                    "No migration found to bring the schema from version '{$current}' to '" . SCHEMA_VERSION . "' -- " .
                    "add config/mariadb-schema-upgrade-{$current}-to-{next version}.sql"
                );
            }
            [$file, $toVersion] = $next;
            self::runSqlFile($file);
            R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', ['schema_version', json_encode($toVersion)]);
            $current = $toVersion;
        }
    }

    /**
     * Find config/mariadb-schema-upgrade-{$from}-to-*.sql, if one exists.
     * Migrations are only ever version-to-next-version -- maintaining a
     * script for every possible (from, to) pair would be far more upgrade
     * scripts than anyone wants to write or test, so a multi-version jump
     * is always walked one step at a time by the caller's loop, never
     * satisfied by a single file. If more than one file's "from" matches
     * (branching, shouldn't normally happen), the one with the numerically
     * lowest "to" wins -- i.e. the very next step, never a skip-ahead.
     *
     * @return array{0: string, 1: string}|null [file path, target version]
     */
    private static function findMigration(string $from): ?array {
        $dir = dirname(__FILE__) . '/../../config';
        $prefix = 'mariadb-schema-upgrade-' . $from . '-to-';

        $candidates = [];
        foreach (glob($dir . '/' . $prefix . '*.sql') ?: [] as $file) {
            $to = substr(basename($file), strlen($prefix), -4); // strip prefix and ".sql"
            $candidates[] = [$file, $to];
        }
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => (int) $a[1] <=> (int) $b[1]);
        return $candidates[0];
    }

    /**
     * Execute every statement in a .sql file via R::exec(), respecting
     * `DELIMITER` directives -- a mysql-CLI-client-only construct these
     * migration files use for stored-procedure bodies. It isn't real SQL
     * and PDO doesn't understand it, so it can't just be split on every
     * semicolon; this tracks the active delimiter manually instead.
     */
    private static function runSqlFile(string $path): void {
        if ( ! is_readable($path)) {
            throw new \RuntimeException("Migration file not found or not readable: {$path}");
        }
        foreach (self::splitSqlStatements(file_get_contents($path)) as $statement) {
            try {
                R::exec($statement);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Migration '{$path}' failed on statement: " . substr($statement, 0, 200) . '... -- ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /** @return string[] */
    private static function splitSqlStatements(string $sql): array {
        $delimiter = ';';
        $statements = [];
        $buffer = '';

        foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
                $delimiter = $m[1];
                continue;
            }

            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
