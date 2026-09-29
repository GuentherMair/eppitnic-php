<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\EppTestCase;
use PDO;
use RedBeanPHP\R;

/**
 * tasks.domain is a RESTRICT foreign key onto domains.domain, which SQLite
 * (the rest of the suite) does not enforce: storing a domain again must
 * rewrite its row, not delete it.
 */
final class DomainStoreForeignKeyTest extends EppTestCase
{
    private const DB_NAME = 'eppitnic_fixtest_a';

    private static ?string $skipReason = null;

    protected function setUp(): void {
        parent::setUp();

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

        if ( ! R::hasDatabase('fixtest_a')) {
            R::addDatabase('fixtest_a', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8', get_current_user(), '');
        }
        R::selectDatabase('fixtest_a', force: true);
        Config::runSqlFile(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');
    }

    protected function tearDown(): void {
        if (self::$skipReason === null && R::hasDatabase('default')) {
            R::selectDatabase('default');
        }
        parent::tearDown();
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

    public function testStoringADomainWithATaskKeepsItsRow(): void {
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REG1', 1)");
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('example.it', 1, 'REG1')");
        $id = (int) R::getCell("SELECT id FROM domains WHERE domain = 'example.it'");
        R::exec("INSERT INTO tasks (domain, `date`, object, action) VALUES ('example.it', '2027-01-01', 'registry', 'delete')");

        $domain = new Domain($this->nic);
        $domain->set('domain', 'example.it');
        $domain->set('registrant', 'REG1');

        $this->assertTrue($domain->storeDB(null, false), $domain->getError());
        $this->assertSame($id, (int) R::getCell("SELECT id FROM domains WHERE domain = 'example.it'"));
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM tasks WHERE domain = 'example.it'"));
    }
}
