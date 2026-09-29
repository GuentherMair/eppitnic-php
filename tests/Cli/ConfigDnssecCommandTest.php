<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigDnssecCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/** `config dnssec on|off` -- a local settings write, audited in `history`. */
final class ConfigDnssecCommandTest extends EppTestCase
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
    }

    private function withDnssec(int $active): void {
        Config::loadForTesting(['dnssec' => ['active' => $active]] + static::SETTINGS);
    }

    private function runCommand(array $argv): string {
        $command = new ConfigDnssecCommand($argv);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    public function testRejectsAnArgumentThatIsNeitherOnNorOff(): void {
        $this->withDnssec(0);

        $this->expectException(UsageError::class);
        (new ConfigDnssecCommand(['--yes', 'sideways']))->run();
    }

    public function testTurningOnStoresItAndRecordsHistory(): void {
        $this->withDnssec(0);

        $this->runCommand(['--yes', 'on']);

        $this->assertSame(['active' => 1], Config::get('dnssec'));
        $this->assertSame('dnssec', R::getCell('SELECT object FROM history'));
    }

    public function testTurningOff(): void {
        $this->withDnssec(1);

        $this->runCommand(['--yes', 'off']);

        $this->assertSame(['active' => 0], Config::get('dnssec'));
    }

    public function testAlreadyInTheStateIsANoOp(): void {
        $this->withDnssec(1);

        $output = $this->runCommand(['--yes', 'on']);

        $this->assertStringContainsString('already on', $output);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testDryRunChangesNothing(): void {
        $this->withDnssec(0);

        $output = $this->runCommand(['--dry-run', '--yes', 'on']);

        $this->assertStringContainsString('would turn', $output);
        $this->assertSame(['active' => 0], Config::get('dnssec'));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $this->withDnssec(0);

        $output = $this->runCommand(['on']);

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame(['active' => 0], Config::get('dnssec'));
    }
}
