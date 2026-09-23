<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\PollProcessCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `poll process`'s own `poll_process.enabled` guard -- the actual
 * drain/reconcile/rotate behaviour when enabled is exercised through
 * PollProcessor/RegistryPasswordChange's own tests, not duplicated here.
 */
final class PollProcessCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => false, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testDisabledIsANoOpAndNeverTouchesTheRegistry(): void {
        $command = new PollProcessCommand([]);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('poll process is off', $output);
        $this->assertStringContainsString('config poll-process-set enabled true', $output);
    }

    /**
     * The enabled check comes before the --dry-run rejection: a disabled job
     * reports itself off rather than throwing over a flag that would not
     * have mattered anyway.
     */
    public function testDisabledShortCircuitsBeforeTheDryRunRejection(): void {
        $command = new PollProcessCommand(['--dry-run']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('poll process is off', $output);
    }

    public function testEnabledReachesThePastDryRunRejection(): void {
        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);

        $this->expectException(UsageError::class);
        (new PollProcessCommand(['--dry-run']))->run();
    }
}
