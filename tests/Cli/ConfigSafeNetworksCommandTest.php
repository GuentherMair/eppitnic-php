<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigSafeNetworksCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config safe-networks` -- a local settings write only, like
 * ConfigSessionSerializeCommandTest, but over a list rather than a flag.
 */
final class ConfigSafeNetworksCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        Config::loadForTesting(static::SETTINGS + ['safe_networks' => ['127.0.0.1/32']]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigSafeNetworksCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    /** @return string[] */
    private function stored(): array {
        return array_map('strval', (array) Config::get('safe_networks'));
    }

    public function testShowsTheCurrentList(): void {
        $output = $this->runCommand([]);

        $this->assertStringContainsString('127.0.0.1/32', $output);
    }

    public function testShowSaysSoWhenTheListIsEmpty(): void {
        Config::set('safe_networks', []);

        $output = $this->runCommand([]);

        $this->assertStringContainsString('every login needs its MFA code', $output);
    }

    public function testAddsANetwork(): void {
        $this->runCommand(['--yes', 'add', '10.0.0.0/8']);

        $this->assertSame(['127.0.0.1/32', '10.0.0.0/8'], $this->stored());
    }

    public function testAddsAnIpv6Network(): void {
        $this->runCommand(['--yes', 'add', '2001:db8::/32']);

        $this->assertContains('2001:db8::/32', $this->stored());
    }

    public function testAddsSeveralAtOnce(): void {
        $this->runCommand(['--yes', 'add', '10.0.0.0/8', '192.168.0.0/16']);

        $this->assertSame(['127.0.0.1/32', '10.0.0.0/8', '192.168.0.0/16'], $this->stored());
    }

    public function testABareAddressBecomesAFullPrefix(): void {
        $this->runCommand(['--yes', 'add', '203.0.113.7']);

        $this->assertContains('203.0.113.7/32', $this->stored());
    }

    /**
     * ClientIp::matches() ignores the host bits, so storing them would claim a
     * narrower range than the setting actually admits.
     */
    public function testHostBitsAreClearedSoTheStoredFormSaysWhatItMatches(): void {
        $this->runCommand(['--yes', 'add', '10.1.2.3/8']);

        $this->assertContains('10.0.0.0/8', $this->stored());
    }

    public function testAddingSomethingAlreadyListedIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'add', '127.0.0.1/32']);

        $this->assertStringContainsString('already listed', $output);
        $this->assertSame(['127.0.0.1/32'], $this->stored());
    }

    public function testAddingTheSameRangeSpeltDifferentlyIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'add', '127.0.0.1']);

        $this->assertStringContainsString('already listed', $output);
        $this->assertSame(['127.0.0.1/32'], $this->stored());
    }

    public function testRemovesANetwork(): void {
        $this->runCommand(['--yes', 'remove', '127.0.0.1/32']);

        $this->assertSame([], $this->stored());
    }

    public function testRemovingSomethingNotListedIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'remove', '10.0.0.0/8']);

        $this->assertStringContainsString('is not listed', $output);
        $this->assertSame(['127.0.0.1/32'], $this->stored());
    }

    public function testClearsTheList(): void {
        $this->runCommand(['--yes', 'clear']);

        $this->assertSame([], $this->stored());
    }

    public function testClearingAnEmptyListIsANoOp(): void {
        Config::set('safe_networks', []);

        $output = $this->runCommand(['--yes', 'clear']);

        $this->assertStringContainsString('unchanged', $output);
    }

    public function testRejectsAnUnknownAction(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'sideways']))->run();
    }

    public function testRejectsAddWithoutANetwork(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'add']))->run();
    }

    public function testRejectsSomethingThatIsNotANetwork(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'add', 'the-office']))->run();
    }

    /**
     * '/oops' would cast to 0, and a /0 prefix matches every address -- a typo
     * must not silently trust the internet.
     */
    public function testRejectsANonNumericPrefix(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'add', '10.0.0.0/oops']))->run();
    }

    public function testRejectsAPrefixWiderThanItsFamily(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'add', '10.0.0.0/33']))->run();
    }

    public function testRejectsArgumentsAfterClear(): void {
        $this->expectException(UsageError::class);
        (new ConfigSafeNetworksCommand(['--yes', 'clear', '10.0.0.0/8']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigSafeNetworksCommand(['--dry-run', 'add', '10.0.0.0/8']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set safe_networks', $output);
        $this->assertSame(['127.0.0.1/32'], $this->stored());
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigSafeNetworksCommand(['add', '10.0.0.0/8']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame(['127.0.0.1/32'], $this->stored());
    }
}
