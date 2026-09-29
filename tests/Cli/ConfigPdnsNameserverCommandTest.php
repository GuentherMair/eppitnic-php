<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigPdnsNameserverCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config pdns-nameserver` -- show/add/remove/clear over `pdns.nameservers`,
 * all through CronjobSettings::set() so validation and the `history` trail
 * are shared with `PATCH /v1/cronjobs/pdns`. See PowerDnsNameserversTest
 * for that shared layer's own coverage; this is about the CLI shape.
 */
final class ConfigPdnsNameserverCommandTest extends EppTestCase
{
    private const DEFAULT_PDNS = [
        'enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600,
        'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
    ];

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + ['pdns' => self::DEFAULT_PDNS]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigPdnsNameserverCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testEmptyListSaysNothingWillSync(): void {
        $output = $this->runCommand([]);
        $this->assertStringContainsString('queue nothing', $output);
    }

    public function testAddAppendsAHost(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);
        $this->assertSame(['ns1.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testAddNormalizesTheHost(): void {
        $this->runCommand(['--yes', 'add', 'NS1.Example.IT.']);
        $this->assertSame(['ns1.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testAddSeveralHostsAtOnce(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it', 'ns2.example.it']);
        $this->assertSame(['ns1.example.it', 'ns2.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testAddingAnAlreadyListedHostIsANoOp(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);
        $output = $this->runCommand(['--yes', 'add', 'ns1.example.it']);

        $this->assertStringContainsString('unchanged', $output);
        $this->assertSame(['ns1.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testAddRequiresAtLeastOneHost(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsNameserverCommand(['--yes', 'add']))->run();
    }

    public function testAddAnIpAddressIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsNameserverCommand(['--yes', 'add', '192.0.2.1']))->run();
    }

    public function testShowListsTheConfiguredHosts(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);

        $output = $this->runCommand([]);

        $this->assertStringContainsString('ns1.example.it', $output);
    }

    public function testRemoveDropsTheMatchingHost(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it', 'ns2.example.it']);

        $this->runCommand(['--yes', 'remove', 'ns1.example.it']);

        $this->assertSame(['ns2.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testRemoveNormalizesTheHost(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);

        $this->runCommand(['--yes', 'remove', 'NS1.Example.IT.']);

        $this->assertSame([], Config::get('pdns')['nameservers']);
    }

    public function testRemoveOfAnUnlistedHostIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'remove', 'ns1.example.it']);
        $this->assertStringContainsString('unchanged', $output);
    }

    public function testRemoveRequiresAtLeastOneHost(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsNameserverCommand(['--yes', 'remove']))->run();
    }

    public function testClearEmptiesTheList(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);

        $this->runCommand(['--yes', 'clear']);

        $this->assertSame([], Config::get('pdns')['nameservers']);
    }

    public function testDryRunWritesNothing(): void {
        $command = new ConfigPdnsNameserverCommand(['--dry-run', 'add', 'ns1.example.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame([], Config::get('pdns')['nameservers']);
    }

    public function testDecliningConfirmationWritesNothing(): void {
        $command = new ConfigPdnsNameserverCommand(['add', 'ns1.example.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame([], Config::get('pdns')['nameservers']);
    }

    public function testASuccessfulWriteIsRecordedToHistory(): void {
        $this->runCommand(['--yes', 'add', 'ns1.example.it']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('ns1.example.it', $row['data']);
    }
}
