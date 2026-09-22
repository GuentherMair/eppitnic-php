<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigDomainSyncCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config domain-sync on|off` -- a local settings write only, no registry
 * session involved. Follows ConfigKeepaliveCommandTest.
 */
final class ConfigDomainSyncCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
    }

    private function withSettings(bool $enabled): void {
        Config::loadForTesting([
            'domain_sync' => ['enabled' => $enabled, 'batch_size' => 25, 'cursor_id' => 0],
        ] + static::SETTINGS);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigDomainSyncCommand($argv);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testRejectsAnArgumentThatIsNeitherOnNorOff(): void {
        $this->withSettings(false);

        $this->expectException(UsageError::class);
        (new ConfigDomainSyncCommand(['--yes', 'sideways']))->run();
    }

    public function testTurningOnFlipsTheSettingWithoutTouchingBatchSizeOrCursor(): void {
        $this->withSettings(false);

        $this->runCommand(['--yes', 'on']);

        $cfg = Config::get('domain_sync');
        $this->assertTrue($cfg['enabled']);
        $this->assertSame(25, $cfg['batch_size']);
        $this->assertSame(0, $cfg['cursor_id']);
    }

    public function testAlreadyOnIsANoOp(): void {
        $this->withSettings(true);

        $output = $this->runCommand(['--yes', 'on']);

        $this->assertStringContainsString('already on', $output);
    }

    public function testAlreadyOffIsANoOp(): void {
        $this->withSettings(false);

        $output = $this->runCommand(['--yes', 'off']);

        $this->assertStringContainsString('already off', $output);
    }

    public function testDryRunDoesNotWrite(): void {
        $this->withSettings(false);

        $command = new ConfigDomainSyncCommand(['--dry-run', 'on']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would turn', $output);
        $this->assertFalse((bool) Config::get('domain_sync')['enabled']);
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $this->withSettings(false);

        $command = new ConfigDomainSyncCommand(['on']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertFalse((bool) Config::get('domain_sync')['enabled']);
    }
}
