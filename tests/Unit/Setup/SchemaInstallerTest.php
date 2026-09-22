<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Setup\SchemaInstaller;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * MariaDB-only, so this drives a real disposable database: `SHOW TABLES LIKE`,
 * `ENGINE=InnoDB` and `json_valid()` are all MariaDB-specific. Per
 * tests/bootstrap.php every test here skips itself when none is reachable.
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

        // RedBeanPHP refuses a second addDatabase() for the same key, so
        // 'setuptest' is registered once per process and reselected after --
        // the DROP/CREATE pair above is what gives each test a clean slate
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
                  'domains', 'transfers', 'messages', 'tasks', 'settings'] as $expected) {
            $this->assertContains($expected, $tables, "missing table '{$expected}'");
        }

        $version = R::getCell("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
        $this->assertSame('"' . SCHEMA_VERSION . '"', $version);
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
