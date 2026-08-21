<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\SessionError;
use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\ConfigMissing;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * An uninstalled application answers the same way whichever verb was typed.
 *
 * Most verbs reach the database through Command::withSession(), which turns any
 * RuntimeException into a SessionError so that an unreachable registry reads as
 * one. ConfigMissing extends RuntimeException, so it was being caught there too:
 * `eppitnic poll list` said "Database configuration missing" and exited 4, while
 * `eppitnic domain info` said "Registry session unavailable" and exited 11 --
 * same condition, two answers, and the louder one sent the reader to look at
 * the network.
 */
final class SetupBootstrapTest extends EppTestCase
{
    protected function tearDown(): void {
        ConfigFile::usePath(null);
        parent::tearDown();
    }

    /**
     * A session that logs in successfully, so the closure is actually reached
     * -- otherwise hello() fails first and every test here passes for the
     * wrong reason.
     */
    private function readyToRun(): void {
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE);
        $this->transport->queue(CommandCatalog::LOGIN_RESPONSE);
        $this->transport->queue(CommandCatalog::LOGIN_RESPONSE);   // logout
    }

    private function command(): Command {
        return new class extends Command {
            public function describe(): string {
                return 'raises whatever it is told to, from inside a session';
            }

            public \Throwable $raise;

            public function run(): int {
                return $this->withSession(function () {
                    throw $this->raise;
                });
            }
        };
    }

    /**
     * The regression: it must arrive at bin/eppitnic as itself, so that the
     * exit code and the advice stay the ones for an uninstalled application.
     */
    public function testConfigMissingIsNotDisguisedAsASessionFailure(): void {
        $this->readyToRun();
        $command = $this->command();
        $command->raise = new ConfigMissing('Database configuration missing');
        $command->useClient($this->nic);

        $this->expectException(ConfigMissing::class);
        $command->run();
    }

    /**
     * And the case that behaviour exists for is untouched: a registry that
     * really is unreachable still reads as a session failure.
     */
    public function testAnUnreachableRegistryIsStillASessionFailure(): void {
        $this->readyToRun();
        $command = $this->command();
        $command->raise = new \RuntimeException('the registry hung up');
        $command->useClient($this->nic);

        $this->expectException(SessionError::class);
        $command->run();
    }

    /**
     * The message says what is wrong and stops there. The advice differs by
     * caller -- the CLI names its verb, the web tier serves the installer
     * instead of saying anything -- so it must not be baked in here.
     */
    public function testTheExceptionMessageCarriesNoCallerSpecificAdvice(): void {
        Config::reset();
        ConfigFile::usePath('/nonexistent/eppitnic/config.php');

        try {
            Config::get('region');
            $this->fail('an unconfigured Config should not have answered');
        } catch (ConfigMissing $e) {
            $this->assertStringNotContainsString('eppitnic setup', $e->getMessage());
            $this->assertStringNotContainsString('browser', $e->getMessage());
            $this->assertStringContainsString('config.php', $e->getMessage());
        }
    }
}
