<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The DNS-sync task rows (deleteDomainDB()/restoreDomainDB()/storeDB()/
 * updateDB() writing to `tasks` with `object='pdns'`) exist purely to feed
 * `eppitnic pdns sync`, which nobody should schedule unless PowerDNS actually
 * serves their zones (docs/INSTALL.md). The insert is gated on the `pdns`
 * setting's `enabled` field in the SQL itself (a `LIKE '%"enabled":true%'`
 * substring match against its JSON-encoded text, the same style as
 * CronjobSettings and every other settings row in this codebase), so an
 * installation that never turns it on never queues work for a job it isn't
 * running.
 */
final class DnsSyncGateTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('DROP TABLE IF EXISTS tasks');
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 1)");
    }

    /** @return array<string, string[]> notice => [domain, action] */
    private function dnsSyncRows(): array {
        $rows = R::getAll("SELECT domain, notice, action FROM tasks WHERE object = 'pdns'");
        $out = [];
        foreach ($rows as $row) {
            $out[$row['notice']] = [$row['domain'], $row['action']];
        }
        return $out;
    }

    private function seedPdns(string $json): void {
        R::exec("INSERT INTO settings (`key`, value) VALUES ('pdns', ?)", [$json]);
    }

    public function testNoSettingsRowAtAllMeansNoInsert(): void {
        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testEnabledFalseMeansNoInsert(): void {
        $this->seedPdns('{"enabled":false,"path":null,"ttl":3600}');

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testEnabledTrueMeansTheRowIsWritten(): void {
        $this->seedPdns('{"enabled":true,"path":"/usr/bin/pdnsutil","ttl":3600}');

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame(['domain deleted' => ['example.it', 'delete']], $this->dnsSyncRows());
    }

    /** field order in the JSON must not matter -- LIKE is a plain substring match */
    public function testEnabledTrueAsTheLastFieldStillOpensTheGate(): void {
        $this->seedPdns('{"path":"/usr/bin/pdnsutil","ttl":3600,"enabled":true}');

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertNotSame([], $this->dnsSyncRows());
    }

    public function testAnUnrelatedSettingDoesNotOpenTheGate(): void {
        R::exec("INSERT INTO settings (`key`, value) VALUES ('some_other_setting', '\"anything\"')");

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // the symmetric restore path
    // ---------------------------------------------------------------

    public function testRestoreIsGatedTheSameWay(): void {
        (new Domain($this->nic))->restoreDomainDB('example.it', Scope::operator(1));
        $this->assertSame([], $this->dnsSyncRows(), 'closed by default');

        $this->seedPdns('{"enabled":true,"path":"/usr/bin/pdnsutil","ttl":3600}');
        (new Domain($this->nic))->restoreDomainDB('example.it', Scope::operator(1));

        $this->assertSame(['domain restored' => ['example.it', 'create']], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // the domain's own soft-delete/restore still happens either way -- the
    // gate guards only the DNS-sync side effect, never the local bookkeeping
    // ---------------------------------------------------------------

    public function testTheDomainIsStillDeactivatedWithTheGateClosed(): void {
        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'example.it'"));
    }
}
