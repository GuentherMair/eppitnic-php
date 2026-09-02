<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigEppServerCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config epp-server` -- a local settings write only, no registry session.
 * Config::set() writes through, so this needs a database: in-memory SQLite,
 * since what is tested is which URL ends up stored, not the dialect.
 */
final class ConfigEppServerCommandTest extends EppTestCase
{
    private const PRODUCTION = 'https://epp.nic.it';
    private const TEST       = 'https://epp.pubtest.nic.it';

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        // EppTestCase::SETTINGS['epp']['server'] is already the pubtest
        // endpoint, which doubles as this suite's "current = test" case
        Config::loadForTesting(static::SETTINGS);
    }

    private function seedServer(string $url): void {
        Config::set('epp', ['server' => $url] + static::SETTINGS['epp']);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testNoArgumentReportsTheCurrentServer(): void {
        $command = new ConfigEppServerCommand([]);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString(self::TEST, $output);
        $this->assertStringContainsString('(test)', $output);
    }

    public function testSetsToProductionWithConfirmation(): void {
        $command = new ConfigEppServerCommand(['--yes', 'production']);
        $errors = fopen('php://memory', 'w+');
        $command->useErrorStream($errors);

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString(self::PRODUCTION, $output);
        $this->assertSame(self::PRODUCTION, Config::get('epp')['server']);

        rewind($errors);
        $warnings = (string) stream_get_contents($errors);
        $this->assertStringContainsString('LIVE production', $warnings);
        $this->assertStringContainsString('separate accounts', $warnings);
    }

    public function testTogglesFromTestToProduction(): void {
        $command = new ConfigEppServerCommand(['--yes', 'toggle']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame(self::PRODUCTION, Config::get('epp')['server']);
    }

    public function testTogglesFromProductionToTest(): void {
        $this->seedServer(self::PRODUCTION);

        $command = new ConfigEppServerCommand(['--yes', 'toggle']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame(self::TEST, Config::get('epp')['server']);
    }

    public function testAlreadySetSkipsTheWriteAndTheWarning(): void {
        $command = new ConfigEppServerCommand(['--yes', 'test']);
        $errors = fopen('php://memory', 'w+');
        $command->useErrorStream($errors);

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('already', $output);
        rewind($errors);
        $this->assertSame('', (string) stream_get_contents($errors));
    }

    public function testDryRunReportsWithoutWriting(): void {
        $command = new ConfigEppServerCommand(['--dry-run', 'production']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set epp.server', $output);
        $this->assertSame(self::TEST, Config::get('epp')['server']);
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigEppServerCommand(['production']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame(self::TEST, Config::get('epp')['server']);
    }

    public function testToggleRefusesOnACustomEndpoint(): void {
        $this->seedServer('https://epp.example.org');

        $this->expectException(UsageError::class);
        (new ConfigEppServerCommand(['--yes', 'toggle']))->run();
    }

    public function testRejectsAnUnknownArgument(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppServerCommand(['bogus']))->run();
    }
}
