<?php

namespace Eppitnic\Service;

/**
 * The `pdns` setting's `nameservers` list: the DNS server hostnames
 * PowerDNS answers for. `Domain::queueDnsSync()` only queues a domain that
 * "touches" one of these -- an empty list means nothing ever syncs.
 *
 * @category    Net
 * @package     Eppitnic\Service\PowerDnsNameservers
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PowerDnsNameservers
{
    public const MAX_SERVERS = 6;

    /**
     * @param array $list hostnames, as given
     * @return string[] normalized (lowercased, trailing dot stripped)
     * @throws \InvalidArgumentException on a non-hostname, an IP address, a
     *         duplicate (after normalizing), or an oversized list
     */
    public static function validate(array $list): array {
        if (count($list) > self::MAX_SERVERS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_SERVERS . ' nameservers');
        }

        $result = [];
        $seen = [];
        foreach ($list as $i => $host) {
            if ( ! is_string($host) || trim($host) === '') {
                throw new \InvalidArgumentException("nameservers[{$i}] must be a hostname");
            }

            $normalized = self::normalize($host);
            if ( ! self::isValidHostname($normalized)) {
                throw new \InvalidArgumentException("nameservers[{$i}] ('{$host}') must be a hostname, not an IP address");
            }
            if (isset($seen[$normalized])) {
                throw new \InvalidArgumentException("duplicate nameserver: {$normalized}");
            }
            $seen[$normalized] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    public static function normalize(string $host): string {
        return rtrim(strtolower(trim($host)), '.');
    }

    /** a real hostname: LDH labels, at least one dot, never an IP address */
    private static function isValidHostname(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,62})?(\.[a-z0-9]([a-z0-9-]{0,62})?)+$/i', $host) === 1;
    }
}
