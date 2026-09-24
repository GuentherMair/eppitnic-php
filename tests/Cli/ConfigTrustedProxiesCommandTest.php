<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigTrustedProxiesCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config trusted-proxies` -- the list editing itself is CidrListCommand's
 * (covered by ConfigSafeNetworksCommandTest); this covers what differs:
 * the catch-all refusal and the `history` row TrustedProxies writes.
 */
final class ConfigTrustedProxiesCommandTest extends EppTestCase
{
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

        Config::loadForTesting(static::SETTINGS + ['trusted_proxies' => ['10.0.0.0/8']]);
    }

    private function runCommand(array $argv): string {
        $command = new ConfigTrustedProxiesCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    /** @return string[] */
    private function stored(): array {
        return array_map('strval', (array) Config::get('trusted_proxies'));
    }

    public function testShowsTheCurrentList(): void {
        $this->assertStringContainsString('10.0.0.0/8', $this->runCommand([]));
    }

    public function testShowSaysSoWhenTheListIsEmpty(): void {
        Config::set('trusted_proxies', []);

        $this->assertStringContainsString('no forwarded header is believed', $this->runCommand([]));
    }

    public function testAddsAProxyAndRecordsHistory(): void {
        $this->runCommand(['--yes', 'add', '172.18.0.1']);

        $this->assertSame(['10.0.0.0/8', '172.18.0.1/32'], $this->stored());
        $row = R::getRow("SELECT * FROM history WHERE object = 'trusted_proxies'");
        $this->assertSame(['10.0.0.0/8', '172.18.0.1/32'], json_decode($row['data'], true)['trusted_proxies']);
    }

    public function testRemovesAProxy(): void {
        $this->runCommand(['--yes', 'remove', '10.0.0.0/8']);

        $this->assertSame([], $this->stored());
    }

    public function testRefusesACatchAllBeforeAsking(): void {
        $this->expectException(UsageError::class);
        (new ConfigTrustedProxiesCommand(['--dry-run', 'add', '0.0.0.0/0']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $output = $this->runCommand(['--dry-run', 'add', '172.18.0.1']);

        $this->assertStringContainsString('would set trusted_proxies', $output);
        $this->assertSame(['10.0.0.0/8'], $this->stored());
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'trusted_proxies'"));
    }
}
