<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigRemoteAuthSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config remote-auth-set` -- the CLI shape around RemoteAuthSettings' own
 * field rules (RemoteAuthSettingsTest covers those); this covers
 * unset-by-omission, confirm/dry-run, and history, mirroring
 * ConfigSmtpSetCommandTest.
 */
final class ConfigRemoteAuthSetCommandTest extends EppTestCase
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
            'remote_auth' => ['enabled' => false, 'header' => null],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigRemoteAuthSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testSetsEnabledTrue(): void {
        $this->runCommand(['--yes', 'enabled', 'true']);
        $this->assertTrue(Config::get('remote_auth')['enabled']);
    }

    public function testSetsTheHeader(): void {
        $this->runCommand(['--yes', 'header', 'X-Remote-User']);
        $this->assertSame('X-Remote-User', Config::get('remote_auth')['header']);
    }

    public function testRejectsAnInvalidHeaderName(): void {
        $this->expectException(UsageError::class);
        (new ConfigRemoteAuthSetCommand(['--yes', 'header', 'Not A Header!']))->run();
    }

    public function testRejectsAForbiddenHeaderName(): void {
        $this->expectException(UsageError::class);
        (new ConfigRemoteAuthSetCommand(['--yes', 'header', 'Authorization']))->run();
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigRemoteAuthSetCommand(['--yes', 'bogus', 'x']))->run();
    }

    public function testOmittingTheValueUnsetsTheHeader(): void {
        $this->runCommand(['--yes', 'header', 'X-Remote-User']);
        $this->runCommand(['--yes', 'header']);
        $this->assertNull(Config::get('remote_auth')['header']);
    }

    public function testOmittingTheValueOnEnabledIsRejected(): void {
        $this->expectException(UsageError::class);
        (new ConfigRemoteAuthSetCommand(['--yes', 'enabled']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigRemoteAuthSetCommand(['--dry-run', 'enabled', 'true']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertFalse(Config::get('remote_auth')['enabled']);
    }

    public function testAlreadySetIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'enabled', 'false']);
        $this->assertStringContainsString('already', $output);
    }

    public function testASuccessfulChangeIsRecordedToHistory(): void {
        $this->runCommand(['--yes', 'enabled', 'true']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'remote_auth'");
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('enabled', $row['data']);
    }
}
