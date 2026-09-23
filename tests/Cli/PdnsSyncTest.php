<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\PdnsSyncCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Applying DNS-sync events to PowerDNS. `pdnsutil` is reached through a
 * setting, so a stub script exercises the real exec() path -- what runs, in
 * what order, and what a non-zero exit does -- with no PowerDNS server
 * anywhere.
 */
final class PdnsSyncTest extends EppTestCase
{
    private string $stub;
    private string $log;
    private string $failFile;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS tasks');
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, object TEXT, action TEXT,
                 active INTEGER DEFAULT 1, executed_time TEXT, exit_code INTEGER, exit_message TEXT, created_time TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, ns TEXT)');

        $dir = sys_get_temp_dir() . '/pdns-sync-test-' . getmypid();
        @mkdir($dir, 0o755, true);
        $this->stub = $dir . '/pdnsutil';
        $this->log = $dir . '/calls.log';
        $this->failFile = $dir . '/fail';
        @unlink($this->log);
        @unlink($this->failFile);

        // Records every invocation and answers according to two marker files:
        // 'fail' makes everything fail, 'zone-<name>' means that zone exists.
        file_put_contents($this->stub, <<<SH
            #!/bin/sh
            echo "\$@" >> "{$this->log}"
            if [ -f "{$this->failFile}" ]; then echo "stub failure"; exit 1; fi
            if [ "\$1" = "list-zone" ]; then
              [ -f "{$dir}/zone-\$2" ] && exit 0
              exit 1
            fi
            if [ "\$1" = "create-zone" ]; then touch "{$dir}/zone-\$2"; fi
            exit 0
            SH);
        chmod($this->stub, 0o755);
        $this->zoneDir = $dir;

        Config::loadForTesting(self::SETTINGS + [
            'pdns' => [
                'enabled' => true, 'path' => $this->stub, 'ttl' => 3600,
                'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
            ],
        ]);
    }

    private string $zoneDir = '';

    protected function tearDown(): void {
        foreach (glob($this->zoneDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->zoneDir);
        parent::tearDown();
    }

    /**
     * @param string[] $args
     */
    private function sync(array $args = []): string {
        $command = new PdnsSyncCommand($args);

        ob_start();
        $this->exitCode = $command->run();
        $command->flush();
        return (string) ob_get_clean();
    }

    private int $exitCode = 0;

    /**
     * @return string[] the arguments of each pdnsutil call, in order
     */
    private function calls(): array {
        if ( ! is_file($this->log)) {
            return [];
        }
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    private function addDomain(string $name, array $nameservers): void {
        $ns = [];
        foreach ($nameservers as $host) {
            $ns[$host] = $host;
        }
        R::exec('INSERT INTO domains (domain, ns) VALUES (?, ?)', [$name, serialize($ns)]);
    }

    /**
     * @param string $created relative to the *database's* clock, which is what
     *        production's CURRENT_TIMESTAMP default writes. PHP's clock is not
     *        the same one -- Client's constructor puts it on Europe/Rome while
     *        the test database answers in UTC -- and building the fixture from
     *        it would be testing against a shape production never produces.
     */
    private function addEvent(string $domain, string $action, string $created = 'now'): void {
        $dbNow = strtotime((string) R::getCell('SELECT CURRENT_TIMESTAMP'));

        R::exec('INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES (?, CURRENT_DATE, \'pdns\', ?, 1, ?)',
            [$domain, $action, date('Y-m-d H:i:s', strtotime($created, $dbNow))]);
    }

    public function testNothingPendingIsNotAnError(): void {
        $output = $this->sync();

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('no pending DNS-sync events', $output);
        $this->assertSame([], $this->calls());
    }

    /**
     * A zone that does not exist yet is created, then its apex NS set is
     * replaced wholesale -- which is what makes create and update the same
     * operation.
     */
    public function testACreateBuildsTheZoneAndItsNsRecords(): void {
        $this->addDomain('example-one.it', ['ns1.example.com', 'ns2.example.com']);
        $this->addEvent('example-one.it', 'create');

        $this->sync();

        $this->assertSame([
            'list-zone example-one.it',
            'create-zone example-one.it ns1.example.com ns2.example.com',
            'delete-rrset example-one.it example-one.it NS',
            'add-record example-one.it example-one.it NS 3600 ns1.example.com.',
            'add-record example-one.it example-one.it NS 3600 ns2.example.com.',
        ], $this->calls());

        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(0, (int) $row['active'], 'the row was not archived');
        $this->assertSame(0, (int) $row['exit_code']);
        $this->assertStringContainsString('synced', $row['exit_message']);
    }

    /**
     * An existing zone is not re-created; only its NS set is reconciled.
     */
    public function testAnUpdateSkipsTheZoneCreation(): void {
        touch($this->zoneDir . '/zone-example-one.it');
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'update');

        $this->sync();

        $this->assertSame([
            'list-zone example-one.it',
            'delete-rrset example-one.it example-one.it NS',
            'add-record example-one.it example-one.it NS 3600 ns1.example.com.',
        ], $this->calls());
    }

    public function testADeleteWaitsOutTheGracePeriod(): void {
        $this->addEvent('example-one.it', 'delete', '-1 hour');

        $output = $this->sync();

        $this->assertSame([], $this->calls(), 'the zone was torn down inside the grace period');
        $this->assertStringContainsString('not due yet', $output);
        $row = R::getRow('SELECT active, executed_time FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a deferred row was archived');
        $this->assertNull($row['executed_time'], 'a deferred row was never attempted, so it has no result yet');
    }

    public function testADeleteRunsOnceItIsDue(): void {
        $this->addEvent('example-one.it', 'delete', '-13 hours');

        $this->sync();

        $this->assertSame(['delete-zone example-one.it'], $this->calls());
        $this->assertSame(0, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'));
    }

    public function testTheGracePeriodIsConfigurable(): void {
        $this->addEvent('example-one.it', 'delete', '-2 hours');

        $this->sync(['--delay-hours=1']);

        $this->assertSame(['delete-zone example-one.it'], $this->calls());
    }

    /**
     * A row whose age cannot be read is not acted on: deleting a zone is the
     * one step here that cannot be undone, so an unknown age waits.
     */
    public function testADeleteWithNoTimestampIsNotApplied(): void {
        R::exec("INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES ('example-one.it', CURRENT_DATE, 'pdns', 'delete', 1, NULL)");

        $output = $this->sync();

        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('no usable created_time', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'));
    }

    /**
     * A failing pdnsutil leaves the row active, so the next run retries it --
     * but the failure is now recorded, unlike before.
     */
    public function testAFailedCallLeavesTheRowForTheNextRun(): void {
        touch($this->failFile);
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $this->sync();

        $this->assertSame(DNS_SYNC_FAILED, $this->exitCode);
        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a failed row was archived');
        $this->assertSame(1, (int) $row['exit_code']);
        $this->assertStringContainsString('FAILED', $row['exit_message']);
    }

    public function testADomainMissingLocallyIsSkipped(): void {
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync();

        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('domain not found locally', $output);
        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a skip stays active for the next run');
        $this->assertSame(1, (int) $row['exit_code']);
        $this->assertStringContainsString('SKIPPED', $row['exit_message']);
    }

    public function testADomainWithNoNameserversIsSkipped(): void {
        $this->addDomain('example-one.it', []);
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync();

        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('no nameservers on record', $output);
    }

    /**
     * A dry run runs nothing and archives nothing -- the events still stand.
     */
    public function testDryRunTouchesNeitherPowerDnsNorTheQueue(): void {
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync(['--dry-run']);

        $this->assertSame([], $this->calls(), 'pdnsutil was run under --dry-run');
        $row = R::getRow('SELECT active, executed_time FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a row was archived under --dry-run');
        $this->assertNull($row['executed_time'], 'a result was recorded under --dry-run');

        $this->assertStringContainsString('create-zone', $output);
        $this->assertStringContainsString('add-record', $output);
    }

    /**
     * A row dated in the future is not due yet -- it must not be picked up
     * at all, regardless of what --delay-hours would otherwise allow.
     */
    public function testARowDatedInTheFutureIsNotPickedUp(): void {
        R::exec("INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES ('example-one.it', ?, 'pdns', 'create', 1, ?)",
            [date('Y-m-d', strtotime('+1 day')), (string) R::getCell('SELECT CURRENT_TIMESTAMP')]);

        $output = $this->sync();

        $this->assertStringContainsString('no pending DNS-sync events', $output);
        $this->assertSame([], $this->calls());
    }

    /**
     * The preview must not claim a zone is missing after showing the
     * create-zone that would have made it -- two events for the same domain
     * would otherwise print two creates.
     */
    public function testDryRunRemembersZonesItWouldHaveCreated(): void {
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');
        $this->addEvent('example-one.it', 'update');

        $output = $this->sync(['--dry-run']);

        $this->assertSame(1, substr_count($output, 'create-zone'), 'the same zone was created twice in the preview');
    }
}
