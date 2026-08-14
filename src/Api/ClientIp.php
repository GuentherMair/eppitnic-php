<?php

namespace Eppitnic\Api;

use Eppitnic\Config;

/**
 * Who the request came from, as far as it can be told.
 *
 * Reads $_SERVER and the proxy headers, so it belongs to the HTTP layer and
 * answers nothing useful from the CLI.
 *
 * Two things decide what this answers, and both are security decisions rather
 * than conveniences:
 *
 * `X-Forwarded-For` is a header the client sends, so it is only believed when
 * the address that actually connected is a configured `trusted_proxies` entry.
 * Believing it unconditionally means anyone can claim any address -- and this
 * class decides who is on a `safe_networks` address (which skips MFA) and which
 * network a login attempt is counted against, so a spoofable answer would give
 * away both.
 *
 * Addresses are compared as packed bytes rather than integers, so IPv6 works
 * the same way IPv4 does. ip2long() cannot represent an IPv6 address at all.
 *
 * @category    Net
 * @package     Eppitnic\Api\ClientIp
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ClientIp
{
    /**
     * The address this request is attributed to.
     *
     * @return string|null the client's address, IPv4 or IPv6, or null when
     *         there is no connection to name -- the CLI, or a synthesised
     *         request in a test
     */
    public static function get(): ?string {
        $remote = self::valid($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === null) {
            return null;
        }

        if ( ! self::isTrustedProxy($remote)) {
            return $remote;
        }

        // Walk the forwarding chain from the right: each entry was added by
        // the hop to its right, so the rightmost address we do not trust is
        // the furthest one that a trusted hop actually observed. Anything left
        // of it was written by someone we have no reason to believe.
        foreach (array_reverse(self::forwardedFor()) as $hop) {
            if ( ! self::isTrustedProxy($hop)) {
                return $hop;
            }
        }

        // every hop is a trusted proxy: the nearest one is the best answer
        return $remote;
    }

    /**
     * @return bool whether the current client's address falls inside $cidr
     */
    public static function inCidr(string $cidr): bool {
        $ip = self::get();
        return $ip !== null && self::matches($ip, $cidr);
    }

    /**
     * Whether $ip falls inside $cidr, for either address family.
     *
     * A bare address with no `/bits` counts as a full-length prefix, so
     * '127.0.0.1' and '127.0.0.1/32' mean the same thing.
     *
     * @param string $cidr e.g. '127.0.0.1/32', '10.0.0.0/8', '2001:db8::/32', '::1'
     */
    public static function matches(string $ip, string $cidr): bool {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        [$network, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $packedNetwork = @inet_pton((string) $network);
        if ($packedNetwork === false) {
            return false;
        }

        // an IPv4 address is never inside an IPv6 range, or the other way
        // round: their packed forms are not even the same length
        if (strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $bits = $bits === null ? strlen($packedIp) * 8 : (int) $bits;
        if ($bits < 0 || $bits > strlen($packedIp) * 8) {
            return false;
        }

        return self::mask($packedIp, $bits) === self::mask($packedNetwork, $bits);
    }

    /**
     * The network $ip belongs to, as a CIDR string.
     *
     * This is what a rate limit counts against, and the prefix lengths are why
     * it is a network rather than an address. A single IPv6 end-site allocation
     * is a /64 -- 18 billion billion addresses -- so counting per address would
     * limit nobody. IPv4 has no equivalent, but a /24 is the usual unit of "one
     * network" and costs an attacker 256 addresses per bucket.
     *
     * @param int $v4bits prefix length for IPv4
     * @param int $v6bits prefix length for IPv6
     * @return string|null e.g. '203.0.113.0/24' or '2001:db8:1:2::/64', null if
     *         $ip is not an address
     */
    public static function network(string $ip, int $v4bits = 24, int $v6bits = 64): ?string {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) === 4 ? $v4bits : $v6bits;
        $bits = max(0, min($bits, strlen($packed) * 8));

        $network = @inet_ntop(self::mask($packed, $bits));
        return $network === false ? null : "{$network}/{$bits}";
    }

    /**
     * $packed with everything past the first $bits zeroed.
     *
     * Byte-wise, so it does not care whether it was handed 4 bytes or 16.
     */
    private static function mask(string $packed, int $bits): string {
        $masked = '';

        for ($i = 0; $i < strlen($packed); $i++) {
            $remaining = $bits - $i * 8;

            if ($remaining >= 8) {
                $masked .= $packed[$i];
            } elseif ($remaining <= 0) {
                $masked .= "\0";
            } else {
                $masked .= chr(ord($packed[$i]) & (0xFF << (8 - $remaining)) & 0xFF);
            }
        }

        return $masked;
    }

    /**
     * @return string[] the X-Forwarded-For chain, left to right, valid entries only
     */
    private static function forwardedFor(): array {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        $raw = $headers['X-Forwarded-For']
            ?? $headers['x-forwarded-for']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? '';

        $hops = [];
        foreach (explode(',', (string) $raw) as $hop) {
            $address = self::valid(trim($hop));
            if ($address !== null) {
                $hops[] = $address;
            }
        }
        return $hops;
    }

    private static function isTrustedProxy(string $ip): bool {
        foreach (self::trustedProxies() as $cidr) {
            if (self::matches($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, string> configured proxy ranges; empty when the
     *         setting is missing, which is the safe reading -- an installation
     *         that has not said which proxies it has does not have any
     */
    private static function trustedProxies(): array {
        try {
            return (array) Config::get('trusted_proxies');
        } catch (\Throwable) {
            return [];
        }
    }

    private static function valid(string $ip): ?string {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;
    }
}
