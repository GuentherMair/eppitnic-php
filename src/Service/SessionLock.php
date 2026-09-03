<?php

namespace Eppitnic\Service;

use RedBeanPHP\R;

/**
 * Serialises EPP commands sent through the shared, kept-alive session -- see
 * SessionState. Whether nic.it tolerates overlapping commands on one
 * authenticated session is unknown, so this removes the question rather than
 * assume either answer.
 *
 * A MariaDB advisory lock (`GET_LOCK`), not a PHP-level mutex: the thing being
 * protected is spread across every PHP-FPM worker and every CLI/cron process
 * this installation runs, all of which already share this database.
 *
 * @category    Net
 * @package     Eppitnic\Service\SessionLock
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SessionLock
{
    /** how long to wait for the lock before giving up and proceeding anyway */
    private const WAIT_SECONDS = 10;

    /**
     * Re-entrancy depth in this process. A 2002/2200/2201 retry re-enters
     * ExecuteQuery() for hello/login and then the original command, all of
     * which would otherwise try to re-acquire a lock this same call already
     * holds -- MariaDB's GET_LOCK is itself re-entrant per connection, but a
     * PHP-side counter makes that explicit rather than relied upon.
     */
    private static int $depth = 0;

    /**
     * Run $fn with the shared session's advisory lock held, if it is not
     * already held by an outer call in this process. Takes a caller-supplied
     * flag rather than reading SessionState::enabled() itself, so that a
     * process where keepalive is off -- the default, and every request that
     * never joins the shared session -- never has to have `keepalive` seeded
     * in the settings it can see at all.
     *
     * @template T
     * @param bool $enabled whether a shared session is in play; false skips
     *             locking entirely
     * @param callable(): T $fn
     * @return T whatever $fn returns
     */
    public static function around(bool $enabled, callable $fn): mixed {
        if ( ! $enabled || self::$depth > 0) {
            return $fn();
        }

        // GET_LOCK() is MariaDB/MySQL-specific, and this codebase does not
        // otherwise require that database (README: "either a MariaDB/MySQL or
        // another database"). Any failure to acquire -- a genuine timeout, or
        // the function not existing at all -- degrades to running unlocked,
        // same as a timeout: the lock is an optimisation against cross-talk,
        // not a correctness invariant, and the ExecuteQuery() retry on
        // 2002/2200/2201 is what actually recovers from it either way.
        try {
            $acquired = (bool) R::getCell('SELECT GET_LOCK(?, ?)', [self::name(), self::WAIT_SECONDS]);
        } catch (\Throwable $e) {
            $acquired = false;
        }
        if ( ! $acquired) {
            fwrite(STDERR, "EPP session lock not obtained within " . self::WAIT_SECONDS .
                "s; proceeding without it -- a concurrent command may still be in flight\n");
        }

        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
            if ($acquired) {
                try {
                    R::getCell('SELECT RELEASE_LOCK(?)', [self::name()]);
                } catch (\Throwable $e) {
                    // nothing to do: the lock either releases on its own when
                    // this connection closes, or was never really MariaDB's
                    // GET_LOCK to begin with
                }
            }
        }
    }

    /**
     * The lock name, scoped by database name -- GET_LOCK names are server-wide,
     * not per-schema, and two installations sharing a database host with
     * different DB_NAME/registry accounts (see compose.multi.yaml.sample) must
     * not serialise against each other. Capped at 64 characters, MariaDB's
     * limit for a lock name.
     */
    private static function name(): string {
        return substr('eppitnic_session_' . (defined('DB_NAME') ? DB_NAME : ''), 0, 64);
    }
}
