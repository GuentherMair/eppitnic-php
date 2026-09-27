<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigPdnsApiCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config pdns-api` -- show/add/remove/clear over `pdns.apis`, all through
 * CronjobSettings::set() so validation and the `history` trail are shared
 * with `PATCH /v1/cronjobs/pdns`. See CronjobSettingsTest/PowerDnsApisTest
 * for that shared layer's own coverage; this is about the CLI shape.
 */
final class ConfigPdnsApiCommandTest extends EppTestCase
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
        $command = new ConfigPdnsApiCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testEmptyListSaysSo(): void {
        $output = $this->runCommand([]);
        $this->assertStringContainsString('no PowerDNS API configured', $output);
    }

    public function testAddAppendsAServer(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=secret']);

        $apis = Config::get('pdns')['apis'];
        $this->assertCount(1, $apis);
        $this->assertSame('https', $apis[0]['protocol']);
        $this->assertSame('ns1.example.com', $apis[0]['host']);
        $this->assertSame(8081, $apis[0]['port']);
        $this->assertSame('secret', $apis[0]['api_key']);
    }

    public function testAddDefaultsThePortTo8081(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com', '--api-key=secret']);

        $this->assertSame(8081, Config::get('pdns')['apis'][0]['port']);
    }

    public function testShowListsRedactedServers(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=secret']);

        $output = $this->runCommand([]);

        $this->assertStringContainsString('https://ns1.example.com:8081', $output);
        $this->assertStringContainsString('key set', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    public function testAddWithoutAKeyOnAKnownEndpointKeepsTheStoredKey(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=secret']);

        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081']);

        $this->assertSame('secret', Config::get('pdns')['apis'][0]['api_key']);
    }

    public function testAddWithoutAKeyOnANewEndpointIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsApiCommand(['--yes', 'add', 'https://ns1.example.com:8081']))->run();
    }

    public function testAddAnInvalidUrlIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsApiCommand(['--yes', 'add', 'not-a-url', '--api-key=k']))->run();
    }

    public function testAddASeventhServerIsAUsageError(): void {
        for ($i = 1; $i <= 6; $i++) {
            $this->runCommand(['--yes', 'add', "https://ns{$i}.example.com:8081", '--api-key=k']);
        }

        $this->expectException(UsageError::class);
        (new ConfigPdnsApiCommand(['--yes', 'add', 'https://ns7.example.com:8081', '--api-key=k']))->run();
    }

    public function testRemoveDropsTheMatchingEntry(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=k1']);
        $this->runCommand(['--yes', 'add', 'https://ns2.example.com:8081', '--api-key=k2']);

        $this->runCommand(['--yes', 'remove', 'https://ns1.example.com:8081']);

        $apis = Config::get('pdns')['apis'];
        $this->assertCount(1, $apis);
        $this->assertSame('ns2.example.com', $apis[0]['host']);
    }

    public function testRemoveOfAnUnlistedUrlIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'remove', 'https://ns1.example.com:8081']);
        $this->assertStringContainsString('unchanged', $output);
    }

    public function testClearEmptiesTheList(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=k1']);

        $this->runCommand(['--yes', 'clear']);

        $this->assertSame([], Config::get('pdns')['apis']);
    }

    public function testDryRunWritesNothing(): void {
        $command = new ConfigPdnsApiCommand(['--dry-run', 'add', 'https://ns1.example.com:8081', '--api-key=k']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame([], Config::get('pdns')['apis']);
    }

    public function testDecliningConfirmationWritesNothing(): void {
        $command = new ConfigPdnsApiCommand(['add', 'https://ns1.example.com:8081', '--api-key=k']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame([], Config::get('pdns')['apis']);
    }

    public function testASuccessfulWriteIsRecordedToHistoryWithTheKeyRedacted(): void {
        $this->runCommand(['--yes', 'add', 'https://ns1.example.com:8081', '--api-key=super-secret']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertStringNotContainsString('super-secret', $row['data']);
        $this->assertStringContainsString('api_key_set', $row['data']);
    }
}
