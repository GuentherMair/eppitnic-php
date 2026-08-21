<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Setup\SchemaInstaller;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * MariaDB-only, so this drives a real, disposable database rather than
 * sqlite::memory: -- Config::migrate()'s own `SHOW TABLES LIKE` and
 * config/mariadb-schema.sql's `ENGINE=InnoDB`/`CHECK (json_valid(...))` are
 * both MariaDB-specific, and state() intentionally uses the same `SHOW
 * TABLES` call rather than a portability shim this codebase has no other use
 * for. Per tests/bootstrap.php's contract, the suite must stay runnable with
 * no MariaDB reachable -- so every test here skips itself when it isn't.
 */
final class SchemaInstallerTest extends TestCase
{
    private const DB_NAME = 'eppitnic_setuptest_schema';

    private static ?string $skipReason = null;

    protected function setUp(): void {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        try {
            $root = new PDO('mysql:host=localhost', get_current_user(), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            self::$skipReason = 'no local MariaDB reachable: ' . $e->getMessage();
            $this->markTestSkipped(self::$skipReason);
        }

        $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        $root->exec('CREATE DATABASE `' . self::DB_NAME . '`');

        // RedBeanPHP refuses a second addDatabase() for the same key, so this
        // registers 'setuptest' once per process and just reselects it after
        // that -- the DROP/CREATE DATABASE pair above is what actually gives
        // each test a clean slate.
        if ( ! R::hasDatabase('setuptest')) {
            R::addDatabase('setuptest', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8', get_current_user(), '');
        }
        R::selectDatabase('setuptest', force: true);
    }

    protected function tearDown(): void {
        if (self::$skipReason !== null) {
            return;
        }
        // Other tests in this same process expect R::'s "current" database to
        // still be 'default' (or unselected) once this class is done with it
        // -- selectDatabase() is process-global state, not per-test.
        if (R::hasDatabase('default')) {
            R::selectDatabase('default');
        }
    }

    public static function tearDownAfterClass(): void {
        if (self::$skipReason !== null) {
            return;
        }
        try {
            $root = new PDO('mysql:host=localhost', get_current_user(), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        } catch (\Throwable) {
            // best effort
        }
    }

    public function testStateIsEmptyDatabaseWithNoTables(): void {
        $this->assertSame(SchemaInstaller::EMPTY_DATABASE, SchemaInstaller::state());
    }

    public function testStateIsEppitnicOnceSettingsExists(): void {
        R::exec('CREATE TABLE settings (`key` VARCHAR(64) PRIMARY KEY, `value` TEXT)');

        $this->assertSame(SchemaInstaller::EPPITNIC, SchemaInstaller::state());
    }

    public function testStateIsUnrelatedWhenTablesExistButNotSettings(): void {
        R::exec('CREATE TABLE something_else (id INT PRIMARY KEY)');

        $this->assertSame(SchemaInstaller::UNRELATED, SchemaInstaller::state());
    }

    public function testInstallAppliesTheSchemaOnAnEmptyDatabase(): void {
        SchemaInstaller::install();

        $tables = array_map(static fn(array $row) => array_values($row)[0], R::getAll('SHOW TABLES'));
        foreach (['users', 'history', 'transactions', 'responses', 'msgqueue', 'contacts',
                  'domains', 'transfers', 'messages', 'reminder', 'settings'] as $expected) {
            $this->assertContains($expected, $tables, "missing table '{$expected}'");
        }

        $version = R::getCell("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
        $this->assertSame('"070000"', $version);
    }

    public function testInstallIsANoOpOnAnExistingEppitnicDatabase(): void {
        R::exec('CREATE TABLE settings (`key` VARCHAR(64) PRIMARY KEY, `value` TEXT)');
        R::exec("INSERT INTO settings (`key`, `value`) VALUES ('marker', '\"untouched\"')");

        SchemaInstaller::install();

        $this->assertSame('"untouched"', R::getCell("SELECT `value` FROM settings WHERE `key` = 'marker'"));
    }

    public function testInstallRefusesAnUnrelatedDatabase(): void {
        R::exec('CREATE TABLE something_else (id INT PRIMARY KEY)');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not look like/');
        SchemaInstaller::install();
    }
}
