<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigPdnsSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config pdns-set` -- a local settings write only, same shape as
 * `config epp-set`. `path` and `ttl` are the two fields `pdns sync` reads
 * (PdnsSyncCommand.php); giving no value unsets a field, falling back to
 * that command's own defaults (PATH lookup, 3600s).
 */
final class ConfigPdnsSetCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'pdnsutil_path' => null,
            'pdnsutil_ttl'  => 3600,
        ]);
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
        $this->assertSame($php, Config::get('pdnsutil_path'));
    }

    public function testRejectsANonExecutablePathWithoutForce(): void {
        $this->expectException(UsageError::class);
        (new ConfigPdnsSetCommand(['--yes', 'path', '/nonexistent/pdnsutil']))->run();
    }

    public function testForceAcceptsANonExecutablePath(): void {
        $this->runCommand(['--yes', '--force', 'path', '/nonexistent/pdnsutil']);
        $this->assertSame('/nonexistent/pdnsutil', Config::get('pdnsutil_path'));
    }

    public function testUnsettingPathGivesNoValue(): void {
        $this->runCommand(['--yes', '--force', 'path', '/nonexistent/pdnsutil']);
        $this->assertNotNull(Config::get('pdnsutil_path'));

        $this->runCommand(['--yes', 'path']);
        $this->assertNull(Config::get('pdnsutil_path'));
    }

    public function testSetsTtlToAPositiveInteger(): void {
        $this->runCommand(['--yes', 'ttl', '7200']);
        $this->assertSame(7200, Config::get('pdnsutil_ttl'));
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
        $this->assertNull(Config::get('pdnsutil_ttl'));
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
        $this->assertSame(3600, Config::get('pdnsutil_ttl'), 'the dry run wrote a change');
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigPdnsSetCommand(['ttl', '7200']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame(3600, Config::get('pdnsutil_ttl'));
    }

    public function testAlreadySetIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'ttl', '3600']);
        $this->assertStringContainsString('already', $output);
    }
}
