<?php

namespace Eppitnic\Service;

use Eppitnic\Config;

/**
 * The shared, kept-alive registry session's state -- the only thing that
 * reads or writes the `keepalive`, `session_serialize`, `session_cookies`
 * and `session_timestamp` settings. `session_timestamp` is the single
 * freshness oracle: there is no `session` table in the schema to consult
 * instead, and it holds local `time()`, not anything the registry sent, so
 * the arithmetic below stays correct regardless of any clock skew against
 * nic.it.
 *
 * @category    Net
 * @package     Eppitnic\Service\SessionState
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SessionState
{
    /** nic.it's idle-session limit, in seconds -- see docs/INSTALL.md */
    public const TIMEOUT = 300;

    /**
     * The cron refreshes once more than this many seconds have passed since
     * the last good response -- comfortably inside TIMEOUT, so one missed
     * per-minute run is still survivable.
     */
    public const REFRESH = 230;

    /**
     * @return bool the `keepalive` setting
     */
    public static function enabled(): bool {
        return (bool) Config::get('keepalive');
    }

    /**
     * Whether commands on the shared session should be serialised against
     * each other -- see SessionLock. Off by default: whether nic.it actually
     * needs this is unconfirmed, so it is opt-in rather than assumed.
     *
     * @return bool the `session_serialize` setting
     */
    public static function serializeEnabled(): bool {
        return (bool) Config::get('session_serialize');
    }

    /**
     * @return array<string, string> the stored session cookies, name => value
     */
    public static function cookies(): array {
        return (array) Config::get('session_cookies');
    }

    /**
     * @return int local unix time of the last good response, or 0 if there is
     *         no session
     */
    public static function timestamp(): int {
        return (int) Config::get('session_timestamp');
    }

    /**
     * @return bool whether the stored session is still within nic.it's idle
     *         timeout
     */
    public static function isFresh(): bool {
        $timestamp = self::timestamp();
        return $timestamp > 0 && (time() - $timestamp) < self::TIMEOUT;
    }

    /**
     * @return bool whether a session exists and is old enough that the cron
     *         should refresh it
     */
    public static function needsRefresh(): bool {
        $timestamp = self::timestamp();
        return $timestamp > 0 && (time() - $timestamp) > self::REFRESH;
    }

    /**
     * Record a good response: always refreshes the timestamp to now, and
     * re-persists the cookies only when they actually changed -- a servlet
     * container may rotate the session id on login, so this is not only an
     * optimisation against a needless write, it is what notices the rotation.
     *
     * @param array<string, string> $cookies the transport's current jar
     */
    public static function remember(array $cookies): void {
        if ($cookies !== self::cookies()) {
            Config::set('session_cookies', $cookies);
        }
        Config::set('session_timestamp', time());
    }

    /**
     * Discard the session: a failed login, or a session the registry no
     * longer recognises. The next attempt starts clean with hello + login.
     */
    public static function forget(): void {
        Config::set('session_cookies', []);
        Config::set('session_timestamp', 0);
    }
}
