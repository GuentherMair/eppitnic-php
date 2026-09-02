<?php

namespace Eppitnic;

use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\ConfigMissing;
use Eppitnic\Support\PasswordGenerator;
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
 * that touches `R::` directly -- not through a Eppitnic object, whose
 * constructor already goes through Config::get() -- must trigger this class
 * first, even if it doesn't need any particular setting value: call
 * Config::init().
 *
 * If config/config.php doesn't exist and the six DB_* constants aren't
 * already defined in-process, connect() (via loadConfig()) throws
 * Setup\ConfigMissing. Creating that file is Setup\Installer's job, driven by
 * `eppitnic setup`, the REST installer (src/Api/Routes/setup.php) or the
 * bundled fallback page (public/setup.html) -- never this class, which does
 * no I/O beyond the database itself.
 *
 * Similarly, config/mariadb-schema.sql and the upgrade script seed the
 * `settings` table with placeholder values for a handful of settings that
 * can't have a real default (jwt_psk, allowed_origins, epp credentials).
 * jwt_psk is filled in the first time the constructor runs (setupSettings());
 * the rest are left at their placeholder until Setup\Installer or a direct
 * settings edit sets them.
 */
final class Config
{
    private static ?self $instance = null;
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
     * Every setting key currently seeded, unredacted.
     *
     * For `eppitnic config show` -- get() rightly requires a key, so a typo
     * fails loudly rather than reading as "unset", but that means there was
     * no way to enumerate what exists at all. Callers still own redacting
     * secrets (jwt_psk, epp.password/pendingPassword) before display; this
     * is the same in-process cache get() already reads from, not a new
     * trust boundary.
     *
     * @return array<string, mixed> key => value
     */
    public static function all(): array {
        return self::instance()->settings;
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
     * validate the DB_* constants it's expected to define, and connect/freeze
     * RedBeanPHP. A no-op after the first call (R::hasDatabase()
     * guards it).
     *
     * Public rather than private: Setup\Installer calls this directly, after
     * define()-ing candidate DB_* constants itself but before
     * config/config.php exists, so it can apply the schema and create the
     * first admin before committing to those credentials by writing the file.
     *
     * @throws ConfigMissing if neither config/config.php nor the six DB_*
     *                       constants exist
     * @throws \RuntimeException if config/config.php is incomplete, or the
     *                            database connection itself fails
     */
    public static function connect(): void {
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
     * Ensure config/config.php is loaded: require it if it exists. Otherwise,
     * if the six DB_* constants are already defined in-process -- Setup\Installer
     * does this while proving candidate credentials, before config/config.php
     * exists -- there is nothing to load. Otherwise there is no configuration
     * at all: nothing here prompts for one any more (see Setup\Installer,
     * Cli\Command\SetupCommand, src/Api/Routes/setup.php).
     *
     * @throws ConfigMissing if neither the file nor the constants exist
     */
    private static function loadConfig(): void {
        if (ConfigFile::exists()) {
            require_once ConfigFile::path();
            return;
        }

        if (defined('DB_TYPE') && defined('DB_HOST') && defined('DB_NAME')
            && defined('DB_CHARSET') && defined('DB_USER') && defined('DB_PASSWORD')) {
            return;
        }

        // States the fault and stops. What to do about it differs by caller --
        // the CLI names its setup verb, the web tier serves the installer
        // instead of a message at all -- so the advice belongs to whoever
        // catches this, not to every reader of it.
        throw new ConfigMissing(
            "Database configuration missing: '" . ConfigFile::path() . "' not found or not readable."
        );
    }

    /**
     * jwt_psk is a real secret, not something a human should type in, so it
     * is always silently regenerated if still at its schema-default
     * empty-string placeholder. Runs once, from the constructor, right after
     * settings are first loaded.
     *
     * allowed_origins and the epp credentials are left at their placeholders
     * if unset -- the application still boots, just with CORS/EPP unusable
     * until Setup\Installer (or a direct settings edit) fills them in.
     */
    private function setupSettings(): void {
        if ($this->settings['jwt_psk'] === '') {
            $this->persistSetting('jwt_psk', PasswordGenerator::signingKey());
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
     *
     * Public rather than private: Setup\Installer calls this (via init())
     * right after SchemaInstaller has decided fresh-vs-existing, so a fresh
     * database's brand-new `settings` table (already stamped at
     * SCHEMA_VERSION by mariadb-schema.sql) is a no-op pass through here
     * rather than a second, parallel notion of "is this up to date."
     */
    public static function migrate(): void {
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
        $dir = EPPITNIC_ROOT . '/config';
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
     *
     * Public rather than private: Setup\SchemaInstaller reuses this to apply
     * config/mariadb-schema.sql on a fresh database, rather than
     * re-implementing DELIMITER-aware SQL-file execution a second time.
     *
     * @throws \RuntimeException on the first failing statement. The message
     *         embeds up to 200 raw characters of that statement's SQL --
     *         fine for a CLI/log audience, but src/Api/Routes/setup.php
     *         truncates it before it can reach an HTTP response body.
     */
    public static function runSqlFile(string $path): void {
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
