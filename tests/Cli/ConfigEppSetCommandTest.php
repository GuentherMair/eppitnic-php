<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigEppSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config epp-set` -- a local settings write only, no registry session
 * opens (unlike `config epp-password`, which every one of these fields is
 * deliberately kept separate from).
 */
final class ConfigEppSetCommandTest extends EppTestCase
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
        $command = new ConfigEppSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testSetsAnIPv4Interface(): void {
        $this->runCommand(['--yes', 'interface', '203.0.113.5']);
        $this->assertSame('203.0.113.5', Config::get('epp')['interface']);
    }

    public function testRejectsANonIPv4Interface(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'interface', 'not-an-ip']))->run();
    }

    public function testRejectsAnIPv6Interface(): void {
        // the field is specifically documented as IPv4
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'interface', '2001:db8::1']))->run();
    }

    public function testSetsLangToItOrEn(): void {
        $this->runCommand(['--yes', 'lang', 'it']);
        $this->assertSame('it', Config::get('epp')['lang']);
    }

    public function testRejectsAnUnsupportedLang(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'lang', 'fr']))->run();
    }

    public function testSetsClTridPrefixUpToFortySevenCharacters(): void {
        $prefix = str_repeat('A', 47);
        $this->runCommand(['--yes', 'cl_trid_prefix', $prefix]);
        $this->assertSame($prefix, Config::get('epp')['cl_trid_prefix']);
    }

    public function testRejectsAClTridPrefixOverFortySevenCharacters(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'cl_trid_prefix', str_repeat('A', 48)]))->run();
    }

    public function testSetsAUsernameEndingInReg(): void {
        $this->runCommand(['--yes', 'username', 'MYCOMPANY-REG']);
        $this->assertSame('MYCOMPANY-REG', Config::get('epp')['username']);
    }

    public function testRejectsAUsernameNotEndingInReg(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'username', 'MYCOMPANY']))->run();
    }

    public function testRejectsAUsernameOutsideThreeToSixteenCharacters(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'username', 'A-VERY-LONG-USERNAME-REG']))->run();
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppSetCommand(['--yes', 'password', 'whatever']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigEppSetCommand(['--dry-run', 'lang', 'it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame('en', Config::get('epp')['lang'], 'the dry run wrote a change');
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigEppSetCommand(['lang', 'it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame('en', Config::get('epp')['lang']);
    }
}
