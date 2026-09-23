<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigDomainSyncSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config domain-sync-set` -- the general `<field> [value]` shape over
 * `domain_sync`'s settings; `config domain-sync <on|off>` reaches the same
 * `enabled` field through a friendlier verb (ConfigDomainSyncCommandTest).
 */
final class ConfigDomainSyncSetCommandTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS + [
            'domain_sync' => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 3, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testSetsBatchSizeWithinRange(): void {
        $command = new ConfigDomainSyncSetCommand(['--yes', 'batch_size', '100']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $stored = Config::get('domain_sync');
        $this->assertSame(100, $stored['batch_size']);
        $this->assertSame(3, $stored['cursor_id'], 'job state was clobbered');
    }

    public function testRejectsAnOutOfRangeBatchSize(): void {
        $this->expectException(UsageError::class);
        (new ConfigDomainSyncSetCommand(['--yes', 'batch_size', '501']))->run();
    }

    public function testSetsEnabledTrue(): void {
        $command = new ConfigDomainSyncSetCommand(['--yes', 'enabled', 'true']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertTrue(Config::get('domain_sync')['enabled']);
    }

    public function testRejectsCursorIdAsAField(): void {
        $this->expectException(UsageError::class);
        (new ConfigDomainSyncSetCommand(['--yes', 'cursor_id', '0']))->run();
    }
}
