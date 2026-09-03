<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\SessionState;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * SessionState's freshness/refresh arithmetic -- the only oracle for whether
 * the shared session is still usable, since there is no `session` table to
 * consult instead. loadForTesting() covers the read-only cases; remember()/
 * forget() write through Config::set(), so those need a real (in-memory)
 * `settings` table behind it.
 */
final class SessionStateTest extends TestCase
{
    private function withTimestamp(int $timestamp, bool $enabled = true, bool $serialize = false): void {
        Config::loadForTesting([
            'keepalive'         => $enabled,
            'session_serialize' => $serialize,
            'session_cookies'   => ['JSESSIONID' => 'abc'],
            'session_timestamp' => $timestamp,
        ]);
    }

    private function withDatabase(int $timestamp): void {
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', ['keepalive', 'true']);
        R::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', ['session_serialize', 'false']);
        R::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', ['session_cookies', '{"JSESSIONID":"abc"}']);
        R::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', ['session_timestamp', (string) $timestamp]);

        $this->withTimestamp($timestamp);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testEnabledReflectsTheKeepaliveSetting(): void {
        $this->withTimestamp(0, enabled: true);
        $this->assertTrue(SessionState::enabled());

        $this->withTimestamp(0, enabled: false);
        $this->assertFalse(SessionState::enabled());
    }

    public function testSerializeEnabledReflectsTheSetting(): void {
        $this->withTimestamp(0, serialize: false);
        $this->assertFalse(SessionState::serializeEnabled());

        $this->withTimestamp(0, serialize: true);
        $this->assertTrue(SessionState::serializeEnabled());
    }

    public function testZeroTimestampIsNeverFreshOrDueForRefresh(): void {
        $this->withTimestamp(0);

        $this->assertFalse(SessionState::isFresh());
        $this->assertFalse(SessionState::needsRefresh());
    }

    public function testJustUnderTheRefreshThresholdDoesNotYetNeedRefresh(): void {
        $this->withTimestamp(time() - (SessionState::REFRESH - 1));

        $this->assertTrue(SessionState::isFresh());
        $this->assertFalse(SessionState::needsRefresh());
    }

    public function testJustOverTheRefreshThresholdNeedsRefresh(): void {
        $this->withTimestamp(time() - (SessionState::REFRESH + 1));

        $this->assertTrue(SessionState::isFresh());
        $this->assertTrue(SessionState::needsRefresh());
    }

    public function testJustUnderTheTimeoutIsStillFresh(): void {
        $this->withTimestamp(time() - (SessionState::TIMEOUT - 1));

        $this->assertTrue(SessionState::isFresh());
    }

    public function testAtOrOverTheTimeoutIsNoLongerFresh(): void {
        $this->withTimestamp(time() - SessionState::TIMEOUT);
        $this->assertFalse(SessionState::isFresh());

        $this->withTimestamp(time() - (SessionState::TIMEOUT + 1));
        $this->assertFalse(SessionState::isFresh());
    }

    public function testRememberRefreshesTheTimestampAndOnlyWritesCookiesWhenTheyChange(): void {
        $this->withDatabase(1000);
        $before = SessionState::cookies();

        SessionState::remember($before);
        $this->assertSame($before, SessionState::cookies());
        $this->assertGreaterThan(1000, SessionState::timestamp());

        SessionState::remember(['JSESSIONID' => 'rotated']);
        $this->assertSame(['JSESSIONID' => 'rotated'], SessionState::cookies());
    }

    public function testForgetClearsCookiesAndTimestamp(): void {
        $this->withDatabase(time());

        SessionState::forget();

        $this->assertSame([], SessionState::cookies());
        $this->assertSame(0, SessionState::timestamp());
        $this->assertFalse(SessionState::isFresh());
    }
}
