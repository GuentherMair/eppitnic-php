<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigKeepaliveCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config keepalive on|off` -- a local settings write, plus, turning off, a
 * logout of whatever session is already open. Follows ConfigEppSetCommandTest.
 */
final class ConfigKeepaliveCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
    }

    private function withSettings(bool $keepalive, int $timestamp = 0): void {
        Config::loadForTesting([
            'keepalive'         => $keepalive,
            'session_cookies'   => ['JSESSIONID' => 'abc123'],
            'session_timestamp' => $timestamp,
        ] + static::SETTINGS);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigKeepaliveCommand($argv);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testRejectsAnArgumentThatIsNeitherOnNorOff(): void {
        $this->withSettings(false);

        $this->expectException(UsageError::class);
        (new ConfigKeepaliveCommand(['--yes', 'sideways']))->run();
    }

    public function testTurningOnWithNoOpenSessionJustFlipsTheSetting(): void {
        $this->withSettings(false);

        $this->runCommand(['--yes', 'on']);

        $this->assertTrue((bool) Config::get('keepalive'));
        $this->assertCount(0, $this->transport->requests, 'turning on must not itself open a session');
    }

    public function testAlreadyOnIsANoOp(): void {
        $this->withSettings(true);

        $output = $this->runCommand(['--yes', 'on']);

        $this->assertStringContainsString('already on', $output);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testAlreadyOffIsANoOp(): void {
        $this->withSettings(false);

        $output = $this->runCommand(['--yes', 'off']);

        $this->assertStringContainsString('already off', $output);
    }

    public function testTurningOffWithNoOpenSessionJustFlipsTheSetting(): void {
        $this->withSettings(true, 0); // on, but no session ever opened

        $this->runCommand(['--yes', 'off']);

        $this->assertFalse((bool) Config::get('keepalive'));
        $this->assertCount(0, $this->transport->requests);
    }

    public function testTurningOffWithAnOpenSessionLogsOutAndClearsState(): void {
        $this->withSettings(true, time());
        $this->transport->queue(CommandCatalog::OK_RESPONSE); // logout

        $output = $this->runCommand(['--yes', 'off']);

        $this->assertStringContainsString('session closed', $output);
        $this->assertCount(1, $this->transport->requests);
        $this->assertStringContainsString('<logout', $this->transport->requests[0]);
        $this->assertFalse((bool) Config::get('keepalive'));
        $this->assertSame([], SessionState::cookies());
        $this->assertSame(0, SessionState::timestamp());
    }

    public function testTurningOffClearsStateEvenWhenTheLogoutCannotReachTheRegistry(): void {
        $this->withSettings(true, time());
        $this->transport->queue(''); // registry unreachable

        $output = $this->runCommand(['--yes', 'off']);

        $this->assertStringContainsString('idle out on its own', $output);
        $this->assertFalse((bool) Config::get('keepalive'));
        $this->assertSame([], SessionState::cookies());
        $this->assertSame(0, SessionState::timestamp());
    }

    public function testDryRunDoesNotWrite(): void {
        $this->withSettings(false);

        $command = new ConfigKeepaliveCommand(['--dry-run', 'on']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would turn', $output);
        $this->assertFalse((bool) Config::get('keepalive'));
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $this->withSettings(false);

        $command = new ConfigKeepaliveCommand(['on']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertFalse((bool) Config::get('keepalive'));
    }
}
