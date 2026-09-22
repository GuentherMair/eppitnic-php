<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The DNS-sync task rows (deleteDomainDB()/restoreDomainDB()/storeDB()/
 * updateDB() writing to `tasks` with `object='pdns'`) exist purely to feed
 * `eppitnic pdns sync`, which nobody should schedule unless PowerDNS actually
 * serves their zones (docs/INSTALL.md). The insert is gated on
 * settings.pdnsutil_path in the SQL itself, so an installation that never
 * configures it never queues work for a job it isn't running.
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
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, user_id INTEGER)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, user_id) VALUES ('example.it', 1)");
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

    public function testNoSettingsRowAtAllMeansNoInsert(): void {
        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testJsonEncodedNullMeansNoInsert(): void {
        // what Config::set('pdnsutil_path', null) actually writes
        R::exec("INSERT INTO settings (`key`, value) VALUES ('pdnsutil_path', 'null')");

        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testAnEmptyStringSettingMeansNoInsert(): void {
        // what Config::set('pdnsutil_path', '') writes -- deliberately blanked
        R::exec("INSERT INTO settings (`key`, value) VALUES ('pdnsutil_path', '\"\"')");

        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testAConfiguredPathMeansTheRowIsWritten(): void {
        // what Config::set('pdnsutil_path', '/usr/bin/pdnsutil') writes
        R::exec("INSERT INTO settings (`key`, value) VALUES ('pdnsutil_path', '\"/usr/bin/pdnsutil\"')");

        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame(['domain deleted' => ['example.it', 'delete']], $this->dnsSyncRows());
    }

    public function testAnUnrelatedSettingDoesNotOpenTheGate(): void {
        R::exec("INSERT INTO settings (`key`, value) VALUES ('some_other_setting', '\"anything\"')");

        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame([], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // the symmetric restore path
    // ---------------------------------------------------------------

    public function testRestoreIsGatedTheSameWay(): void {
        (new Domain($this->nic))->restoreDomainDB('example.it', 1, true);
        $this->assertSame([], $this->dnsSyncRows(), 'closed by default');

        R::exec("INSERT INTO settings (`key`, value) VALUES ('pdnsutil_path', '\"/usr/bin/pdnsutil\"')");
        (new Domain($this->nic))->restoreDomainDB('example.it', 1, true);

        $this->assertSame(['domain restored' => ['example.it', 'create']], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // the domain's own soft-delete/restore still happens either way -- the
    // gate guards only the DNS-sync side effect, never the local bookkeeping
    // ---------------------------------------------------------------

    public function testTheDomainIsStillDeactivatedWithTheGateClosed(): void {
        (new Domain($this->nic))->deleteDomainDB('example.it', 1, true);

        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'example.it'"));
    }
}
