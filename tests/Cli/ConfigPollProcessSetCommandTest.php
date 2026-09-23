<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigPollProcessSetCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config poll-process-set` -- enabled, frequency_minutes. `enabled`
 * defaults true (see CronjobSettings): switching it off stops the shared
 * EPP password from auto-rotating on a passwdReminder.
 */
final class ConfigPollProcessSetCommandTest extends EppTestCase
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
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testSetsFrequencyMinutes(): void {
        $command = new ConfigPollProcessSetCommand(['--yes', 'frequency_minutes', '10']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame(10, Config::get('poll_process')['frequency_minutes']);
    }

    public function testSetsEnabledToFalse(): void {
        $command = new ConfigPollProcessSetCommand(['--yes', 'enabled', 'false']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertFalse(Config::get('poll_process')['enabled']);
    }

    public function testASuccessfulChangeIsRecordedToHistory(): void {
        $command = new ConfigPollProcessSetCommand(['--yes', 'enabled', 'false']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('poll_process', $row['data']);
    }
}
