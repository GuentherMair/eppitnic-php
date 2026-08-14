<?php

namespace Eppitnic\Api;

use Eppitnic\Config;
use RedBeanPHP\R;

/**
 * How many failed logins one network may make before it is turned away.
 *
 * Counted from the `history` table's own `security` rows rather than a counter
 * of its own: the failures have to be recorded anyway, and a second store
 * would be a second thing to keep in step with the first. It also means the
 * evidence behind a block is the same rows an operator reads to understand it.
 *
 * Counted per *network*, not per address -- see ClientIp::network(). A limit on
 * single addresses stops nobody who has a /64, which is what an ordinary IPv6
 * customer connection is.
 *
 * The window slides: it asks how many failures the network has produced in the
 * last `timespan` seconds, so a blocked network is let back in as the old
 * failures age out, with no lock to clear and nothing to expire on a schedule.
 *
 * A successful login does not reset the count. It would let anyone holding one
 * working account clear the evidence for the whole network before continuing to
 * guess at the others.
 *
 * @category    Net
 * @package     Eppitnic\Api\LoginRateLimit
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class LoginRateLimit
{
    /**
     * Used when the setting is missing -- an installation whose schema predates
     * this feature is still protected, rather than unlimited.
     */
    private const DEFAULTS = [
        'max_failures' => 10,
        'timespan'     => 900,
        'ipv4_prefix'  => 24,
        'ipv6_prefix'  => 48,
    ];

    /**
     * The network this request is counted against.
     *
     * @return string|null null when there is no address to attribute it to,
     *         which is the CLI and the test suite rather than anything on the
     *         network
     */
    public static function network(): ?string {
        $ip = ClientIp::get();
        if ($ip === null) {
            return null;
        }

        $settings = self::settings();
        return ClientIp::network($ip, (int) $settings['ipv4_prefix'], (int) $settings['ipv6_prefix']);
    }

    /**
     * @return bool whether this network has spent its allowance
     */
    public static function isExceeded(): bool {
        return self::remaining() === 0;
    }

    /**
     * @return int how many further failures this network may make; 0 means
     *         blocked. A request with no address to attribute is never blocked
     *         -- there is nothing to count it against, and refusing every such
     *         request would break the CLI rather than any attacker.
     */
    public static function remaining(): int {
        $settings = self::settings();
        $max = (int) $settings['max_failures'];

        if ($max <= 0) {
            return PHP_INT_MAX; // 0 or negative disables the limit
        }

        $network = self::network();
        if ($network === null) {
            return $max;
        }

        return max(0, $max - self::failuresFor($network, (int) $settings['timespan']));
    }

    /**
     * @return int seconds until the oldest failure in the window ages out, so a
     *         blocked caller can be told when to come back rather than left to
     *         guess
     */
    public static function retryAfter(): int {
        $settings = self::settings();
        $network = self::network();
        if ($network === null) {
            return 0;
        }

        $timespan = (int) $settings['timespan'];
        $oldest = R::getCell(
            "SELECT MIN(`timestamp`) FROM history
             WHERE `network` = :network AND object = 'security' AND action = 'denied'
               AND `timestamp` > :cutoff",
            [':network' => $network, ':cutoff' => self::cutoff($timespan)]
        );

        if ($oldest === null) {
            return 0;
        }

        // both instants come from the database's clock, so their difference
        // does not depend on PHP and the database agreeing about the timezone
        $elapsed = strtotime(self::databaseNow()) - strtotime((string) $oldest);
        return max(1, $timespan - $elapsed);
    }

    /**
     * @return int failures recorded for $network inside the window
     */
    private static function failuresFor(string $network, int $timespan): int {
        // 'denied' is the only action counted: a successful login is 'login'
        // and a rate-limit block is 'read', so neither a legitimate user nor a
        // network that keeps knocking extends how long the door stays closed
        return (int) R::getCell(
            "SELECT COUNT(*) FROM history
             WHERE `network` = :network AND object = 'security' AND action = 'denied'
               AND `timestamp` > :cutoff",
            [':network' => $network, ':cutoff' => self::cutoff($timespan)]
        );
    }

    /**
     * The start of the window, on the database's clock.
     *
     * Computed here rather than as `NOW() - INTERVAL n SECOND` so the query is
     * the same statement on any engine, and read from the database rather than
     * PHP so it cannot drift from the timestamps it is compared against.
     */
    private static function cutoff(int $timespan): string {
        return date('Y-m-d H:i:s', strtotime(self::databaseNow()) - $timespan);
    }

    private static function databaseNow(): string {
        return (string) R::getCell('SELECT CURRENT_TIMESTAMP');
    }

    /**
     * @return array{max_failures: int, timespan: int, ipv4_prefix: int, ipv6_prefix: int}
     */
    private static function settings(): array {
        try {
            return ((array) Config::get('login_ratelimit')) + self::DEFAULTS;
        } catch (\Throwable) {
            return self::DEFAULTS;
        }
    }
}
