<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigDomainReapSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config domain-reap-set` -- a thin AbstractCronjobSetCommand wrapper, same
 * shape as `config pdns-set` (see ConfigPdnsSetCommandTest and
 * CronjobSettingsTest for that shared layer's own coverage).
 */
final class ConfigDomainReapSetCommandTest extends EppTestCase
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
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigDomainReapSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testSetsEnabledToFalse(): void {
        $this->runCommand(['--yes', 'enabled', 'false']);
        $this->assertFalse(Config::get('domain_reap_deletions')['enabled']);
    }

    public function testUnsettingFrequencyGivesNoValue(): void {
        $this->runCommand(['--yes', 'frequency_minutes', '30']);
        $this->assertSame(30, Config::get('domain_reap_deletions')['frequency_minutes']);

        $this->runCommand(['--yes', 'frequency_minutes']);
        $this->assertNull(Config::get('domain_reap_deletions')['frequency_minutes']);
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigDomainReapSetCommand(['--yes', 'batch_size', '10']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigDomainReapSetCommand(['--dry-run', 'enabled', 'false']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertTrue(Config::get('domain_reap_deletions')['enabled']);
    }
}
