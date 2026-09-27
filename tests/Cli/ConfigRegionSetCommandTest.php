<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigRegionSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config region-set` -- the CLI shape around RegionSettings (RegionRouteTest
 * covers the field rules): set, refuse, no unsetting, dry run, history.
 */
final class ConfigRegionSetCommandTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS);
    }

    private function runCommand(array $argv): string {
        $command = new ConfigRegionSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    public function testSetsTheTimezoneAndAuditsIt(): void {
        $this->runCommand(['--yes', 'timezone', 'Europe/Vienna']);

        $this->assertSame('Europe/Vienna', Config::get('region')['timezone']);
        $this->assertSame('region', R::getCell("SELECT object FROM history"));
    }

    public function testRejectsAnUnknownTimezone(): void {
        $this->expectException(UsageError::class);
        (new ConfigRegionSetCommand(['--yes', 'timezone', 'Mars/Olympus']))->run();
    }

    public function testAFieldCannotBeUnset(): void {
        $this->expectException(UsageError::class);
        (new ConfigRegionSetCommand(['--yes', 'lc_time']))->run();
    }

    public function testDryRunWritesNothing(): void {
        $output = $this->runCommand(['--dry-run', 'lc_monetary', 'de_DE.UTF-8']);

        $this->assertStringContainsString('would set', $output);
        $this->assertSame(static::SETTINGS['region']['lc_monetary'], Config::get('region')['lc_monetary']);
    }
}
