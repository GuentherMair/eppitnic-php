<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\SessionKeepaliveCommand;
use Eppitnic\Config;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `session keepalive` -- the cron job that refreshes the shared session with
 * `hello` before nic.it's idle timeout. Meant to run once a minute, so it
 * must stay quiet whenever there is nothing to do.
 */
final class SessionKeepaliveCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
    }

    private function withSettings(bool $keepalive, int $timestamp): void {
        Config::loadForTesting([
            'keepalive'         => $keepalive,
            'session_cookies'   => ['JSESSIONID' => 'abc123'],
            'session_timestamp' => $timestamp,
        ] + static::SETTINGS);
    }

    private function runCommand(): array {
        $command = new SessionKeepaliveCommand([]);
        $command->useClient($this->nic);
        $command->useErrorStream($errors = fopen('php://memory', 'w+'));

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        rewind($errors);
        return [$code, $output, (string) stream_get_contents($errors)];
    }

    public function testDoesNothingWhenKeepaliveIsOff(): void {
        $this->withSettings(keepalive: false, timestamp: time() - SessionState::REFRESH - 1);

        [$code, $output, $errors] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertSame('', $errors);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testDoesNothingWhenThereIsNoSessionYet(): void {
        $this->withSettings(keepalive: true, timestamp: 0);

        [$code, $output] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testDoesNothingWhileTheSessionIsStillFresh(): void {
        $this->withSettings(keepalive: true, timestamp: time() - (SessionState::REFRESH - 1));

        [$code, $output] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testSendsHelloAndRefreshesTheTimestampWhenDue(): void {
        $this->withSettings(keepalive: true, timestamp: time() - (SessionState::REFRESH + 1));
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE);

        [$code, $output] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('refreshed', $output);
        $this->assertCount(1, $this->transport->requests);
        $this->assertStringContainsString('<hello', $this->transport->requests[0]);
        $this->assertGreaterThan(time() - 5, SessionState::timestamp());
    }

    public function testAFailedHelloLeavesTheStoredSessionAlone(): void {
        $due = time() - (SessionState::REFRESH + 1);
        $this->withSettings(keepalive: true, timestamp: $due);
        $this->transport->queue(''); // no greeting -- registry unreachable

        [$code, , $errors] = $this->runCommand();

        $this->assertSame(HELLO_FAILED, $code);
        $this->assertStringContainsString('session state left as is', $errors);
        // unchanged: a transient failure must not discard a session that may
        // still be perfectly valid
        $this->assertSame($due, SessionState::timestamp());
        $this->assertSame(['JSESSIONID' => 'abc123'], SessionState::cookies());
    }
}
