<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigSessionSerializeCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config session-serialize on|off` -- a local settings write only, unlike
 * `config keepalive` there is no registry session to open or close either
 * way. Follows ConfigEppSetCommandTest.
 */
final class ConfigSessionSerializeCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        Config::loadForTesting(static::SETTINGS);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigSessionSerializeCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testRejectsAnArgumentThatIsNeitherOnNorOff(): void {
        $this->expectException(UsageError::class);
        (new ConfigSessionSerializeCommand(['--yes', 'sideways']))->run();
    }

    public function testTurnsOn(): void {
        $this->runCommand(['--yes', 'on']);

        $this->assertTrue((bool) Config::get('session_serialize'));
    }

    public function testAlreadyOnIsANoOp(): void {
        Config::set('session_serialize', true);

        $output = $this->runCommand(['--yes', 'on']);

        $this->assertStringContainsString('already on', $output);
    }

    public function testAlreadyOffIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'off']);

        $this->assertStringContainsString('already off', $output);
    }

    public function testTurnsOff(): void {
        Config::set('session_serialize', true);

        $this->runCommand(['--yes', 'off']);

        $this->assertFalse((bool) Config::get('session_serialize'));
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigSessionSerializeCommand(['--dry-run', 'on']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would turn', $output);
        $this->assertFalse((bool) Config::get('session_serialize'));
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigSessionSerializeCommand(['on']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertFalse((bool) Config::get('session_serialize'));
    }
}
