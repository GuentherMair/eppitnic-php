<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;
use Eppitnic\Service\EppSession;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * EppSession::run() with `keepalive` on: whether it opens a session, reuses
 * one, or replays a single command after the registry drops it out from under
 * it. keepalive off is every other wire test in this suite, unchanged.
 */
final class EppSessionKeepaliveTest extends EppTestCase
{
    private const OBJECT_DOES_NOT_EXIST = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2303"><msg lang="en">Object does not exist</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    private const SESSION_LOST = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2002"><msg lang="en">Command use error</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    protected function setUp(): void {
        parent::setUp();

        // remember()/forget() write through Config::set() -- a real (if
        // in-memory) `settings` table behind loadForTesting()'s cache, same
        // pattern as PasswordRotationTest
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        parent::tearDown();
    }

    /**
     * @param int $timestamp local unix time SessionState should report as the
     *            last good response; 0 means no session at all
     */
    private function keepaliveOn(int $timestamp): void {
        Config::loadForTesting([
            'keepalive'         => true,
            'session_cookies'   => ['JSESSIONID' => 'abc123'],
            'session_timestamp' => $timestamp,
        ] + static::SETTINGS);

        $this->nic->keepalive = true;
    }

    /** one round trip through EppSession::run(), sending a single `poll req` */
    private function runOnePoll(): bool {
        return EppSession::run(
            fn($nic, $session) => $session->poll(false, 'req'),
            false,
            $this->nic
        );
    }

    public function testFreshSessionSendsNeitherHelloNorLoginAndNoLogout(): void {
        $this->keepaliveOn(time());
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->assertTrue($this->runOnePoll());

        $this->assertCount(1, $this->transport->requests);
        $this->assertStringContainsString('<poll', $this->transport->requests[0]);
    }

    public function testStaleSessionSendsHelloAndLoginFirst(): void {
        $this->keepaliveOn(time() - SessionState::TIMEOUT - 1);
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE); // hello()
        $this->transport->queue(CommandCatalog::OK_RESPONSE);       // login()
        $this->transport->queue(CommandCatalog::OK_RESPONSE);       // poll()

        $this->assertTrue($this->runOnePoll());

        $this->assertCount(3, $this->transport->requests);
        $this->assertStringContainsString('<hello', $this->transport->requests[0]);
        $this->assertStringContainsString('<login', $this->transport->requests[1]);
        $this->assertStringContainsString('<poll', $this->transport->requests[2]);
    }

    public function testNoSessionAtAllSendsHelloAndLoginFirst(): void {
        $this->keepaliveOn(0);
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->assertTrue($this->runOnePoll());
        $this->assertCount(3, $this->transport->requests);
    }

    public function testSessionLostRetriesOnceAfterLoggingInAgain(): void {
        $this->keepaliveOn(time()); // fresh: no hello/login before the poll
        $this->transport->queue(self::SESSION_LOST);          // poll -- 2002
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE); // hello()
        $this->transport->queue(CommandCatalog::OK_RESPONSE);       // login()
        $this->transport->queue(CommandCatalog::OK_RESPONSE);       // poll, resent

        $this->assertTrue($this->runOnePoll());

        $this->assertCount(4, $this->transport->requests);
        $this->assertStringContainsString('<poll', $this->transport->requests[0]);
        $this->assertStringContainsString('<hello', $this->transport->requests[1]);
        $this->assertStringContainsString('<login', $this->transport->requests[2]);
        $this->assertStringContainsString('<poll', $this->transport->requests[3]);
    }

    public function testContentFailureDoesNotRetry(): void {
        $this->keepaliveOn(time());
        $this->transport->queue(self::OBJECT_DOES_NOT_EXIST);

        $this->assertFalse($this->runOnePoll());

        $this->assertCount(1, $this->transport->requests);
    }

    public function testTransportFailureThrowsTheSessionUnavailableException(): void {
        $this->keepaliveOn(time());
        $this->transport->queue(''); // empty body -- what a dead connection looks like

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EPP session unavailable: connection failed');

        $this->runOnePoll();
    }

    public function testKeepaliveOffLogsOutAfterward(): void {
        // the default: EppTestCase::SETTINGS carries keepalive => false
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE); // the poll
        $this->transport->queue(CommandCatalog::OK_RESPONSE); // logout

        $this->runOnePoll();

        $this->assertCount(4, $this->transport->requests);
        $this->assertStringContainsString('<logout', $this->transport->requests[3]);
    }

    /**
     * A client on a different host than `epp.server` must never join the
     * shared session -- DomainRestoreCommand's "-deleted" endpoint is exactly
     * this shape (see Client::$keepalive's docblock).
     */
    public function testAServerOverrideNeverJoinsTheSharedSession(): void {
        Config::loadForTesting([
            'keepalive'         => true,
            'session_cookies'   => ['JSESSIONID' => 'abc123'],
            'session_timestamp' => time(),
        ] + static::SETTINGS);

        $override = new Client(static::SETTINGS['epp']['server_deleted']);
        $override->setTransport($transport = new FakeTransport());
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE); // logout

        EppSession::run(fn($nic, $session) => $session->poll(false, 'req'), false, $override);

        // hello + login + poll + logout: the full connect-per-request
        // sequence, proving keepalive never applied to this client
        $this->assertCount(4, $transport->requests);
        $this->assertStringContainsString('<logout', $transport->requests[3]);
    }

    /**
     * Registry password rotation builds its own Session directly, never
     * through EppSession::run() -- see RegistryPasswordChange::apply(). It
     * must never read or write the shared session's state, since
     * passwordWorks() deliberately tries a password it may expect to fail.
     */
    public function testPasswordRotationNeverTouchesSharedSessionState(): void {
        Config::loadForTesting([
            'keepalive'         => true,
            'session_cookies'   => ['JSESSIONID' => 'do-not-touch'],
            'session_timestamp' => time(),
        ] + static::SETTINGS);

        RegistryPasswordChange::useClientFactory(function () {
            $client = new Client();
            $client->setTransport($transport = new FakeTransport());
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queue(self::SESSION_LOST); // the password is refused
            return $client;
        });

        RegistryPasswordChange::adopt('a-password-the-registry-rejects');

        $this->assertSame(
            ['JSESSIONID' => 'do-not-touch'],
            SessionState::cookies(),
            'a probe login must never clear the shared session it has nothing to do with'
        );
    }
}
