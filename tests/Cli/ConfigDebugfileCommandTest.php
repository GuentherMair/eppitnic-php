<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigDebugfileCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/** `config debugfile` -- over DebugFile, whose rules DebugFileTest covers */
final class ConfigDebugfileCommandTest extends EppTestCase
{
    private string $dir;

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

        $this->dir = sys_get_temp_dir() . '/eppitnic-debugfile-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->dir = (string) realpath($this->dir);
        putenv('EPPITNIC_VAR_DIR=' . $this->dir);
        Config::loadForTesting(['debugfile' => ''] + static::SETTINGS);
    }

    protected function tearDown(): void {
        putenv('EPPITNIC_VAR_DIR');
        @unlink($this->dir . '/epp.log');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0: string, 1: string} stdout, stderr */
    private function runCommand(array $argv): array {
        $command = new ConfigDebugfileCommand($argv);
        $err = fopen('php://memory', 'w+');
        $command->useErrorStream($err);
        ob_start();
        $this->assertSame(0, $command->run());
        $out = (string) ob_get_clean();
        rewind($err);
        return [$out, (string) stream_get_contents($err)];
    }

    public function testShowsThatLoggingIsOff(): void {
        $this->assertStringContainsString('is off', $this->runCommand([])[0]);
    }

    public function testTurnsLoggingOnWithAWarning(): void {
        [$out, $err] = $this->runCommand(['--yes', 'epp.log']);

        $this->assertSame($this->dir . '/epp.log', Config::get('debugfile'));
        $this->assertFileExists($this->dir . '/epp.log');
        $this->assertStringContainsString('Passwords, auth codes and session cookies are masked', $err);
        $this->assertStringContainsString('logging registry traffic to', $out);
    }

    public function testDeleteRemovesTheFileAndTurnsLoggingOff(): void {
        $this->runCommand(['--yes', 'epp.log']);
        $this->runCommand(['--yes', 'delete']);

        $this->assertSame('', Config::get('debugfile'));
        $this->assertFileDoesNotExist($this->dir . '/epp.log');
    }

    public function testRefusesANewLogWhileOneIsRecording(): void {
        $this->runCommand(['--yes', 'epp.log']);

        $this->expectException(UsageError::class);
        (new ConfigDebugfileCommand(['--yes', 'other.log']))->run();
    }

    public function testDryRunWritesNothing(): void {
        $this->runCommand(['--yes', '--dry-run', 'epp.log']);

        $this->assertSame('', Config::get('debugfile'));
        $this->assertFileDoesNotExist($this->dir . '/epp.log');
    }

    public function testRefusesAFileThatIsNoLog(): void {
        $this->expectException(UsageError::class);
        (new ConfigDebugfileCommand(['--yes', 'shell.php']))->run();
    }
}
