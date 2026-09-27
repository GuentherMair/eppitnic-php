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
 * serves their zones (docs/INSTALL.md). The insert is gated on
 * `pdns.enabled`, a non-empty `pdns.apis`, and the domain's NS set touching
 * a configured `pdns.nameservers` entry -- read via `JSON_VALUE`/
 * `JSON_LENGTH`/`JSON_OVERLAPS`, guarded by `JSON_VALID` so invalid JSON
 * never reaches them. SQLite lacks a MariaDB-compatible `JSON_VALUE`/
 * `JSON_LENGTH`/`JSON_OVERLAPS` (its own `JSON_EXTRACT` is close enough to
 * use as-is), so this test registers shims matching what MariaDB 11.8
 * actually returns (a JSON boolean is `'1'`/`'0'`, not `'true'`/`'false'`).
 */
final class DnsSyncGateTest extends EppTestCase
{
    private const NS1 = 'ns1.example.it';

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('DROP TABLE IF EXISTS tasks');
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1,
                 reseller_id INTEGER, ns TEXT, status TEXT, cr_date TEXT, ex_date TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, ns, status, cr_date, ex_date) VALUES (?, 1, ?, '', '', '')",
            ['example.it', $this->serializedNs([self::NS1])]);

        $this->registerJsonShims();
    }

    /** @param string[] $names */
    private function serializedNs(array $names): string {
        $ns = [];
        foreach ($names as $name) {
            $ns[$name] = ['name' => $name];
        }
        return serialize($ns);
    }

    /** @param string[] $names */
    private function seedDomainNs(string $domain, array $names): void {
        R::exec('UPDATE domains SET ns = ? WHERE domain = ?', [$this->serializedNs($names), $domain]);
    }

    /**
     * `JSON_VALID` and `JSON_EXTRACT` are SQLite's own (case-insensitively
     * matched); `JSON_VALUE`/`JSON_LENGTH` only ever see `$.enabled`/
     * `$.apis` here, so a single top-level key lookup is all they need.
     */
    private function registerJsonShims(): void {
        $pdo = R::getPDO();

        @$pdo->sqliteCreateFunction('JSON_VALUE', static function (?string $json, string $path): mixed {
            $value = self::topLevelValue($json, $path);
            if (is_bool($value)) {
                // returned as an int, not '1'/'0': SQLite (unlike MariaDB)
                // does not coerce TEXT to INTEGER for `= 1`, and a boolean
                // is the only thing this codebase's gate ever compares
                return $value ? 1 : 0;
            }
            if ($value === null || is_array($value)) {
                return null;
            }
            return $value;
        });

        @$pdo->sqliteCreateFunction('JSON_LENGTH', static function (?string $json, string $path = '$'): ?int {
            $value = $path === '$' ? json_decode((string) $json, true) : self::topLevelValue($json, $path);
            return is_array($value) ? count($value) : null;
        });

        // MariaDB: 1 on a shared element, 0 against an empty list, NULL if
        // either side is not a JSON array (in particular, a missing path,
        // which JSON_EXTRACT already answers NULL for)
        @$pdo->sqliteCreateFunction('JSON_OVERLAPS', static function (?string $a, ?string $b): ?int {
            $left = json_decode((string) $a, true);
            $right = json_decode((string) $b, true);
            if ( ! is_array($left) || ! is_array($right)) {
                return null;
            }
            return array_intersect($left, $right) !== [] ? 1 : 0;
        });
    }

    /** @return mixed null if $json is invalid, not an object, or lacks $path's key */
    private static function topLevelValue(?string $json, string $path): mixed {
        $decoded = json_decode((string) $json, true);
        if ( ! is_array($decoded)) {
            return null;
        }
        $key = ltrim($path, '$.');
        return array_key_exists($key, $decoded) ? $decoded[$key] : null;
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

    /** enabled, one API, nameservers matching self::NS1 -- the open gate */
    private function seedOpenGate(): void {
        $this->seedPdns(json_encode([
            'enabled' => true,
            'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']],
            'nameservers' => [self::NS1],
            'ttl' => 3600,
        ]));
    }

    public function testNoSettingsRowAtAllMeansNoInsert(): void {
        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testEnabledFalseMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => false, 'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']], 'nameservers' => [self::NS1]]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    /** enabled alone is not enough: apis must be non-empty too */
    public function testEnabledTrueWithNoApisMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => true, 'apis' => [], 'nameservers' => [self::NS1]]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testEnabledTrueWithApisMissingMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => true, 'nameservers' => [self::NS1]]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    /** the whole point of the nameservers change: an empty list syncs nothing */
    public function testEnabledTrueWithApisButNoNameserversMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => true, 'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']], 'nameservers' => []]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    /** nameservers missing entirely (JSON_EXTRACT -> NULL) is the same as empty */
    public function testEnabledTrueWithNameserversMissingMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => true, 'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']]]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    /** configured, but for a different nameserver than the one on record */
    public function testNonOverlappingNameserversMeansNoInsert(): void {
        $this->seedPdns(json_encode(['enabled' => true, 'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']], 'nameservers' => ['ns-other.example.it']]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
    }

    public function testEveryConditionMetMeansTheRowIsWritten(): void {
        $this->seedOpenGate();

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame(['domain deleted' => ['example.it', 'delete']], $this->dnsSyncRows());
    }

    /** field order in the JSON must not matter -- JSON_VALUE parses, it does not substring-match */
    public function testFieldOrderDoesNotAffectTheGate(): void {
        $this->seedPdns(json_encode(['nameservers' => [self::NS1], 'apis' => [['protocol' => 'http', 'host' => 'ns', 'port' => 8081, 'api_key' => 'k']], 'enabled' => true]));

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertNotSame([], $this->dnsSyncRows());
    }

    /** invalid JSON must not reach JSON_VALUE/JSON_LENGTH/JSON_OVERLAPS -- the CASE guard's whole job */
    public function testInvalidJsonMeansNoInsertAndNoError(): void {
        $this->seedPdns('not json');

        (new Domain($this->nic))->deleteDomainDB('example.it', Scope::operator(1));

        $this->assertSame([], $this->dnsSyncRows());
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

        $this->seedOpenGate();
        (new Domain($this->nic))->restoreDomainDB('example.it', Scope::operator(1));

        $this->assertSame(['domain restored' => ['example.it', 'create']], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // "touches" = the old or the new NS set matches -- exercised via
    // updateDB(), which reads the old NS before storageUpdate() overwrites it
    // ---------------------------------------------------------------

    public function testMovingOntoAConfiguredNameserverQueuesAnUpdate(): void {
        $this->seedOpenGate();
        $this->seedDomainNs('example.it', ['ns-old.example.it']); // did not touch NS1

        $d = new Domain($this->nic);
        $d->addNS(self::NS1); // now touches it
        $d->updateDB('example.it', Scope::operator(1), ['ns']);

        $this->assertSame(['nameservers changed' => ['example.it', 'update']], $this->dnsSyncRows());
    }

    public function testMovingAwayFromAConfiguredNameserverQueuesADelete(): void {
        $this->seedOpenGate();
        $this->seedDomainNs('example.it', [self::NS1]); // touched it

        $d = new Domain($this->nic);
        $d->addNS('ns-new.example.it'); // no longer touches it
        $d->updateDB('example.it', Scope::operator(1), ['ns']);

        $rows = $this->dnsSyncRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['example.it', 'delete'], $rows['nameservers changed (moved away)']);
    }

    public function testNeitherOldNorNewNameserverIsConfiguredQueuesNothing(): void {
        $this->seedOpenGate();
        $this->seedDomainNs('example.it', ['ns-old.example.it']);

        $d = new Domain($this->nic);
        $d->addNS('ns-new.example.it');
        $d->updateDB('example.it', Scope::operator(1), ['ns']);

        $this->assertSame([], $this->dnsSyncRows());
    }

    // ---------------------------------------------------------------
    // a delayed delete must not tear down a zone a later create/update
    // brings back -- see Domain::queueDnsSync()
    // ---------------------------------------------------------------

    public function testRestoringADomainSupersedesItsPendingDelete(): void {
        $this->seedOpenGate();
        R::exec("INSERT INTO tasks (domain, date, notice, object, action, active) VALUES ('example.it', CURRENT_DATE, 'domain deleted', 'pdns', 'delete', 1)");

        (new Domain($this->nic))->restoreDomainDB('example.it', Scope::operator(1));

        $delete = R::getRow("SELECT active, exit_message FROM tasks WHERE object = 'pdns' AND action = 'delete'");
        $this->assertSame(0, (int) $delete['active'], 'the pending delete was not superseded');
        $this->assertStringContainsString('superseded', $delete['exit_message']);

        $create = R::getRow("SELECT active FROM tasks WHERE object = 'pdns' AND action = 'create'");
        $this->assertSame(1, (int) $create['active']);
    }

    public function testAnUpdateThatQueuesSupersedesAPendingDelete(): void {
        $this->seedOpenGate();
        $this->seedDomainNs('example.it', ['ns-old.example.it']);
        R::exec("INSERT INTO tasks (domain, date, notice, object, action, active) VALUES ('example.it', CURRENT_DATE, 'domain deleted', 'pdns', 'delete', 1)");

        $d = new Domain($this->nic);
        $d->addNS(self::NS1);
        $d->updateDB('example.it', Scope::operator(1), ['ns']);

        $this->assertSame(0, (int) R::getCell("SELECT active FROM tasks WHERE object = 'pdns' AND action = 'delete'"));
    }

    /** a delete queued for an unrelated domain must never be touched */
    public function testSupersedingOnlyAffectsTheSameDomain(): void {
        $this->seedOpenGate();
        R::exec("INSERT INTO domains (domain, reseller_id, ns, status, cr_date, ex_date) VALUES ('other.it', 1, ?, '', '', '')", [$this->serializedNs([self::NS1])]);
        R::exec("INSERT INTO tasks (domain, date, notice, object, action, active) VALUES ('other.it', CURRENT_DATE, 'domain deleted', 'pdns', 'delete', 1)");

        (new Domain($this->nic))->restoreDomainDB('example.it', Scope::operator(1));

        $this->assertSame(1, (int) R::getCell("SELECT active FROM tasks WHERE domain = 'other.it'"));
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
