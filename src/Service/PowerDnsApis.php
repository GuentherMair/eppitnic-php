<?php

namespace Eppitnic\Service;

/**
 * The `pdns` setting's `apis` list: validate/redact/baseUrl for one entry
 * (`protocol`, `host`, `port`, `api_key`), shared by `CronjobSettings`,
 * `config pdns-api` and `ConfigShowCommand`. `api_key` is write-only: a
 * blank/omitted key on `validate()` keeps the stored key of the same
 * endpoint (protocol+host+port), the same pattern `Notifier`'s SMTP
 * password uses.
 *
 * @category    Net
 * @package     Eppitnic\Service\PowerDnsApis
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PowerDnsApis
{
    /** every configured API gets every change -- a sync run's own cost cap */
    public const MAX_SERVERS = 6;

    /**
     * @param array $list as given (e.g. request body or CLI input)
     * @param array $stored the currently-saved list, for a blank key to fall
     *              back to
     * @return array normalized entries: protocol, host (lowercased),
     *         port (int), api_key
     * @throws \InvalidArgumentException on a bad entry, a duplicate
     *         endpoint, an oversized list, or a new endpoint with no key
     */
    public static function validate(array $list, array $stored): array {
        if (count($list) > self::MAX_SERVERS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_SERVERS . ' PowerDNS API servers');
        }

        $result = [];
        $seen = [];

        foreach ($list as $i => $entry) {
            if ( ! is_array($entry)) {
                throw new \InvalidArgumentException("apis[{$i}] must be an object");
            }

            $protocol = strtolower((string) ($entry['protocol'] ?? ''));
            if ( ! in_array($protocol, ['http', 'https'], true)) {
                throw new \InvalidArgumentException("apis[{$i}].protocol must be 'http' or 'https'");
            }

            $host = strtolower(trim((string) ($entry['host'] ?? '')));
            if ($host === '' || ! self::isValidHost($host)) {
                throw new \InvalidArgumentException("apis[{$i}].host must be a hostname, IPv4 or IPv6 address");
            }

            $port = self::parsePort($entry['port'] ?? null, $i);

            $endpoint = "{$protocol}://{$host}:{$port}";
            if (isset($seen[$endpoint])) {
                throw new \InvalidArgumentException("duplicate PowerDNS API endpoint: {$endpoint}");
            }
            $seen[$endpoint] = true;

            $apiKey = trim((string) ($entry['api_key'] ?? ''));
            if ($apiKey === '') {
                $apiKey = self::storedKey($stored, $protocol, $host, $port);
                if ($apiKey === null) {
                    throw new \InvalidArgumentException("apis[{$i}] ({$endpoint}) needs an api_key: it is a new endpoint");
                }
            } elseif (preg_match('/\s/', $apiKey) === 1) {
                throw new \InvalidArgumentException("apis[{$i}].api_key must not contain whitespace");
            }

            $result[] = ['protocol' => $protocol, 'host' => $host, 'port' => $port, 'api_key' => $apiKey];
        }

        return $result;
    }

    /** @return array each entry with api_key replaced by api_key_set */
    public static function redact(array $list): array {
        return array_map(static function (array $api): array {
            return [
                'protocol'    => $api['protocol'],
                'host'        => $api['host'],
                'port'        => $api['port'],
                'api_key_set' => ($api['api_key'] ?? '') !== '',
            ];
        }, $list);
    }

    /** IPv6 is bracketed; IPv4/hostname is not. */
    public static function baseUrl(array $api): string {
        $host = (string) $api['host'];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = "[{$host}]";
        }
        return "{$api['protocol']}://{$host}:{$api['port']}";
    }

    private static function storedKey(array $stored, string $protocol, string $host, int $port): ?string {
        foreach ($stored as $api) {
            if (($api['protocol'] ?? null) === $protocol
                && strtolower((string) ($api['host'] ?? '')) === $host
                && (int) ($api['port'] ?? 0) === $port
            ) {
                $key = (string) ($api['api_key'] ?? '');
                return $key !== '' ? $key : null;
            }
        }
        return null;
    }

    private static function isValidHost(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,62})?(\.[a-z0-9]([a-z0-9-]{0,62})?)*$/i', $host) === 1;
    }

    private static function parsePort(mixed $value, int|string $i): int {
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $port = (int) $value;
        } else {
            throw new \InvalidArgumentException("apis[{$i}].port must be a whole number");
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("apis[{$i}].port must be between 1 and 65535");
        }
        return $port;
    }
}
