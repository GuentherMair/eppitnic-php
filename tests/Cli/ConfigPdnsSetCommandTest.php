<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigPdnsSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config pdns-set` -- a thin AbstractCronjobSetCommand wrapper over
 * CronjobSettings, which does the actual validation/persistence/history
 * write; see CronjobSettingsTest for that layer's own coverage. This test
 * is about the CLI shape: unset-by-omission, --force, confirm/dry-run/
 * already-set, and that an unknown field is a UsageError.
 */
final class ConfigPdnsSetCommandTest extends EppTestCase
{
    private const DEFAULT_PDNS = [
        'enabled' => false, 'path' => null, 'ttl' => 3600,
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
        $command = new ConfigPdnsSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testSetsPathToAnExecutableFile(): void {
        // php's own binary is guaranteed executable wherever this test runs
        $php = PHP_BINARY;
        $this->runCommand(['--yes', 'path', $php]);
        $this->assertSame($php, Config::get('pdns')['path']);
    }

    public function testRejectsANonExecutablePathWithoutForce(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'path', '/nonexistent/pdnsutil']))->run();
    }

    public function testForceAcceptsANonExecutablePath(): void {
        $this->runCommand(['--yes', '--force', 'path', '/nonexistent/pdnsutil']);
        $this->assertSame('/nonexistent/pdnsutil', Config::get('pdns')['path']);
    }

    public function testUnsettingPathGivesNoValue(): void {
        $this->runCommand(['--yes', '--force', 'path', '/nonexistent/pdnsutil']);
        $this->assertNotNull(Config::get('pdns')['path']);

        $this->runCommand(['--yes', 'path']);
        $this->assertNull(Config::get('pdns')['path']);
    }

    public function testSetsTtlToAPositiveInteger(): void {
        $this->runCommand(['--yes', 'ttl', '7200']);
        $this->assertSame(7200, Config::get('pdns')['ttl']);
    }

    public function testRejectsANonNumericTtl(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'ttl', 'abc']))->run();
    }

    public function testRejectsAZeroOrNegativeTtl(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'ttl', '0']))->run();
    }

    public function testUnsettingTtlGivesNoValue(): void {
        $this->runCommand(['--yes', 'ttl']);
        $this->assertNull(Config::get('pdns')['ttl']);
    }

    public function testSetsEnabledToTrue(): void {
        $this->runCommand(['--yes', 'enabled', 'true']);
        $this->assertTrue(Config::get('pdns')['enabled']);
    }

    public function testSetsFrequencyMinutesWithinRange(): void {
        $this->runCommand(['--yes', 'frequency_minutes', '30']);
        $this->assertSame(30, Config::get('pdns')['frequency_minutes']);
    }

    public function testRejectsAFrequencyMinutesOutOfRange(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'frequency_minutes', '5000']))->run();
    }

    /** setting one field leaves the rest of the object untouched */
    public function testSettingOneFieldPreservesTheOthers(): void {
        $this->runCommand(['--yes', 'ttl', '7200']);
        $pdns = Config::get('pdns');
        $this->assertSame(7200, $pdns['ttl']);
        $this->assertSame(12, $pdns['delay_hours']);
        $this->assertFalse($pdns['enabled']);
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'bogus', 'whatever']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigPdnsSetCommand(['--dry-run', 'ttl', '7200']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame(3600, Config::get('pdns')['ttl'], 'the dry run wrote a change');
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigPdnsSetCommand(['ttl', '7200']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame(3600, Config::get('pdns')['ttl']);
    }

    public function testAlreadySetIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'ttl', '3600']);
        $this->assertStringContainsString('already', $output);
    }

    public function testASuccessfulChangeIsRecordedToHistory(): void {
        $this->runCommand(['--yes', 'ttl', '7200']);
        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('pdns', $row['data']);
    }
}
