<?php

namespace Eppitnic\Api;

/**
 * Who the request came from, as far as it can be told.
 *
 * Reads $_SERVER and the proxy headers, so it belongs to the HTTP layer and
 * answers nothing useful from the CLI.
 *
 * @category    Net
 * @package     Eppitnic\Api\ClientIp
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ClientIp
{
    // -----------------------------------------------------------------
    // network / client IP
    // -----------------------------------------------------------------

    /**
     * best-effort client IP, preferring X-Forwarded-For (validated as IPv4)
     * over the raw connecting address
     *
     * @return string|false the client's IPv4 address, or false if it couldn't be determined
     */
    public static function get(): string|false {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : $_SERVER;

        if (array_key_exists('X-Forwarded-For', $headers) && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $headers['X-Forwarded-For'];
        } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers) && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $headers['HTTP_X_FORWARDED_FOR'];
        } else {
            return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }
    }

    /**
     * @param string $cidr an IPv4 CIDR range, e.g. '127.0.0.1/32'
     * @return bool whether the current client's IP falls inside $cidr
     */
    public static function inCidr(string $cidr): bool {
        [$network, $bits] = explode('/', $cidr);
        $ip      = ip2long(self::get());
        $network = ip2long($network);
        $mask    = ~((1 << (32 - (int)$bits)) - 1);
        return ($ip & $mask) === ($network & $mask);
    }
}
