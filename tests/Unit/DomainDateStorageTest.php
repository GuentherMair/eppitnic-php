<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * A fetched domain carries the registry's full timestamps; `cr_date` and
 * `ex_date` are DATE columns, which MariaDB's strict mode refuses them for.
 * SQLite stores anything, so the stored value itself is what is checked.
 */
final class DomainDateStorageTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'settings', 'tasks'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 user_id INTEGER, registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT,
                 action TEXT, active INTEGER DEFAULT 1, exit_code INTEGER, exit_message TEXT)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REG1', 1)");
        R::exec("INSERT INTO domains (domain, reseller_id, registrant, cr_date, ex_date) VALUES ('example.it', 1, 'REG1', '', '')");
    }

    /** a Domain as fetch() leaves it: full ISO timestamps from the registry */
    private function fetchedDomain(): Domain {
        $domain = new Domain($this->nic);
        foreach ([
            'domain'     => 'example.it',
            'registrant' => 'REG1',
            'crDate'     => '2026-09-27T15:56:37.000+02:00',
            'exDate'     => '2027-09-27T23:59:59.000+02:00',
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(Domain::class, $property);
            $reflection->setValue($domain, $value);
        }
        return $domain;
    }

    public function testUpdateStoresOnlyTheDatePart(): void {
        $this->assertTrue($this->fetchedDomain()->updateDB('example.it', Scope::operator(1), ['admin']));

        $row = R::getRow("SELECT cr_date, ex_date FROM domains WHERE domain = 'example.it'");
        $this->assertSame(['cr_date' => '2026-09-27', 'ex_date' => '2027-09-27'], $row);
    }

    public function testStoreKeepsOnlyTheDatePart(): void {
        $this->assertTrue($this->fetchedDomain()->storeDB(1, false));

        $row = R::getRow("SELECT cr_date, ex_date FROM domains WHERE domain = 'example.it'");
        $this->assertSame(['cr_date' => '2026-09-27', 'ex_date' => '2027-09-27'], $row);
    }

    public function testStoreKeepsTheRowIdAndLastInvoice(): void {
        // a later row, so a re-inserted example.it could not get its id back
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('other.it', 1, 'REG1')");
        R::exec("UPDATE domains SET active = 0, last_invoice = '2026-01-01 00:00:00' WHERE domain = 'example.it'");
        $id = (int) R::getCell("SELECT id FROM domains WHERE domain = 'example.it'");

        $this->assertTrue($this->fetchedDomain()->storeDB(1, false));

        $row = R::getRow("SELECT id, active, last_invoice FROM domains WHERE domain = 'example.it'");
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame('2026-01-01 00:00:00', $row['last_invoice']);
        $this->assertSame(2, (int) R::getCell("SELECT COUNT(*) FROM domains"));
    }
}
