<?php

namespace Eppitnic;

use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\ConfigMissing;
use Eppitnic\Support\PasswordGenerator;
use RedBeanPHP\R;

/**
 * The database connection, made lazily, plus the `settings` table loaded once
 * and served from memory. Also owns schema versioning, migrate() walking the
 * upgrade chain to SCHEMA_VERSION. Direct `R::` callers must init() first.
 */
final class Config
{
    /**
     * The `epp` fields safe to publish, for GET /v1/session/epp and
     * `config show`. An allow-list: the registry password lives in the same
     * setting, so a field added later is withheld until it is named here.
     */
    public const EPP_PUBLIC_FIELDS = [
        'server', 'server_deleted', 'port', 'interface',
        'username', 'lang', 'cl_trid_prefix', 'lastPasswordUpdate',
    ];

    private static ?self $instance = null;
    private array $settings = [];

    /** connect(), migrate(), load the settings, then setupSettings() */
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

    /** Connect and migrate without reading a setting -- for `R::` callers. */
    public static function init(): void {
        self::instance();
    }

    /**
     * Install a settings array as the singleton's state, touching no database.
     * Test suite only: production get()/set() would then answer from a cache
     * nothing backs, and set() would REPLACE INTO a table it never connected
     * to.
     *
     * @param array $settings the complete settings map, as get() should answer
     *              it
     */
    public static function loadForTesting(array $settings): void {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->settings = $settings;
        self::$instance = $instance;
    }

    /** Drop the singleton so the next call rebuilds it. Test teardown only. */
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
     * Every seeded key, unredacted -- get() needs a key, so nothing could
     * enumerate them. Callers own redacting secrets before display.
     *
     * @return array<string, mixed> key => value
     */
    public static function all(): array {
        return self::instance()->settings;
    }

    /**
     * persist + keep the in-process cache consistent (used by EPP password
     * rotation)
     *
     * @param string $key setting name
     * @param mixed $value new value
     */
    public static function set(string $key, mixed $value): void {
        // instance() first, so the `settings` table exists before writing to
        // it -- set() may be the first Config call in the process
        $instance = self::instance();
        R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', [$key, json_encode($value)]);
        $instance->settings[$key] = $value;
    }

    /**
     * Load config/config.php, validate the DB_* constants, connect and freeze
     * RedBeanPHP. A no-op after the first call. Public because Setup\Installer
     * calls it with define()d candidates before that file exists.
     *
     * @throws ConfigMissing if neither the file nor the six constants exist
     * @throws \RuntimeException if the config is incomplete or the connect
     *                       fails
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
                // R::setup() only sets the DSN; force the connection here, in
                // this try, so a failure surfaces with a clear message
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
     * require config/config.php, or accept DB_* constants already defined
     * in-process -- Setup\Installer defines them while proving credentials.
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

        // No advice here: the CLI names its setup verb and the web tier serves
        // the installer instead, so it belongs to whoever catches this
        throw new ConfigMissing(
            "Database configuration missing: '" . ConfigFile::path() . "' not found or not readable."
        );
    }

    /**
     * Generate jwt_psk if it is still at its placeholder -- it is a secret, not
     * something to type in. allowed_origins and the epp credentials are left
     * alone: the application boots without them, just with CORS/EPP unusable.
     */
    private function setupSettings(): void {
        // `?? ''` treats absent like placeholder: a settings table assembled
        // some other way can lack the row, and null !== '' would skip the
        // generation and fail every JWT operation later instead
        if (($this->settings['jwt_psk'] ?? '') === '') {
            $this->persistSetting('jwt_psk', PasswordGenerator::signingKey());
        }
    }

    /**
     * set() without its instance() call, so setupSettings() can use it from
     * inside the constructor -- the public set() would recurse.
     */
    private function persistSetting(string $key, mixed $value): void {
        R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', [$key, json_encode($value)]);
        $this->settings[$key] = $value;
    }

    /**
     * Bring the schema up to SCHEMA_VERSION. Uses `R::` directly because it
     * runs from the constructor, before $instance exists, so instance() would
     * recurse. Public for Setup\Installer, where a fresh database is a no-op.
     */
    public static function migrate(): void {
        if (empty(R::getAll("SHOW TABLES LIKE 'settings'"))) {
            // no stamp to read: assume the pre-versioning baseline and let the
            // loop find its way from there by the normal filename lookup
            $current = '060700';
        } else {
            $current = R::getCell("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
            if ($current === null || $current === false) {
                // `settings` existed already but predates this versioning
                // feature -- its shape already matches SCHEMA_VERSION, just
                // stamp it
                R::exec('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)', ['schema_version', json_encode(SCHEMA_VERSION)]);
                return;
            }
            $current = json_decode($current, true);
        }

        // Migrations run forwards only. Said here, or the loop below reports a
        // missing file as if that were the problem.
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
     * Find config/mariadb-schema-upgrade-{$from}-to-*.sql. Only ever one step:
     * a multi-version jump is walked by the caller's loop, never satisfied by
     * a single file, so on a tie the lowest "to" wins.
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
     * Run every statement in a .sql file, honouring `DELIMITER` -- a mysql-CLI
     * construct PDO does not understand, so this cannot just split on ';'.
     * Public so Setup\SchemaInstaller can apply mariadb-schema.sql with it.
     *
     * @throws \RuntimeException on the first failing statement, quoting 200
     *         characters of its SQL (setup.php truncates that for HTTP)
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
