<?php

namespace Eppitnic\Service;

use Eppitnic\Api\ClientIp;
use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `trusted_proxies` setting: the peers whose `X-Forwarded-For` (and,
 * under remote authentication's header mode, whose username header) is
 * believed. Shared by `config trusted-proxies` and `/v1/trusted-proxies`.
 *
 * @category    Net
 * @package     Eppitnic\Service\TrustedProxies
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class TrustedProxies
{
    /** @return string[] the stored list, as stored */
    public static function get(): array {
        return array_values(array_map('strval', (array) Config::get('trusted_proxies')));
    }

    /**
     * $cidrs as canonical networks, in the given order, without duplicates.
     *
     * @param mixed[] $cidrs addresses, optionally with a /prefix
     * @return string[]
     * @throws \InvalidArgumentException if one is not a network, or covers
     *         every address of its family
     */
    public static function normalize(array $cidrs): array {
        $result = [];
        foreach ($cidrs as $value) {
            $cidr = is_string($value) ? ClientIp::canonicalCidr($value) : null;
            if ($cidr === null) {
                $shown = is_scalar($value) ? (string) $value : gettype($value);
                throw new \InvalidArgumentException("'{$shown}' is not a network -- give an address, optionally with a /prefix");
            }
            // a /0 would let any client claim any address (and identity)
            if (str_ends_with($cidr, '/0')) {
                throw new \InvalidArgumentException("'{$cidr}' covers every address -- list only your own proxies");
            }
            if ( ! in_array($cidr, $result, true)) {
                $result[] = $cidr;
            }
        }
        return $result;
    }

    /**
     * Replace the whole list, and record the change to `history`.
     *
     * @param mixed[] $cidrs the complete new list
     * @return string[] what was stored
     * @throws \InvalidArgumentException see normalize()
     */
    public static function set(array $cidrs, int $userId): array {
        $desired = self::normalize($cidrs);

        Config::set('trusted_proxies', $desired);
        History::record('trusted_proxies', 0, 'update', ['trusted_proxies' => $desired], $userId);

        return $desired;
    }
}
