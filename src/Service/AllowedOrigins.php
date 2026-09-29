<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `allowed_origins` setting: the browser origins whose requests the CORS
 * middleware lets through. Shared by `config allowed-origins` and
 * `/v1/allowed-origins`.
 *
 * @category    Net
 * @package     Eppitnic\Service\AllowedOrigins
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class AllowedOrigins
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** @return string[] the stored list, as stored */
    public static function get(): array {
        return array_values(array_map('strval', (array) Config::get('allowed_origins')));
    }

    /**
     * One origin as a browser sends it in `Origin`, which the middleware
     * compares by string equality: lower case, no default port, no path.
     *
     * @return string|null the canonical origin, or null if $value is none
     */
    public static function canonical(string $value): ?string {
        $parts = parse_url(trim($value));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ( ! isset(self::DEFAULT_PORTS[$scheme])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $port = isset($parts['port']) && $parts['port'] !== self::DEFAULT_PORTS[$scheme] ? ":{$parts['port']}" : '';
        return $scheme . '://' . strtolower($parts['host']) . $port;
    }

    /**
     * $origins as canonical origins, in the given order, without duplicates.
     *
     * @param mixed[] $origins e.g. "https://epp.example.it"
     * @return string[]
     * @throws \InvalidArgumentException if one is not an http(s) origin
     */
    public static function normalize(array $origins): array {
        $result = [];
        foreach ($origins as $value) {
            $origin = is_string($value) ? self::canonical($value) : null;
            if ($origin === null) {
                $shown = is_scalar($value) ? (string) $value : gettype($value);
                throw new \InvalidArgumentException(
                    "'{$shown}' is not an origin -- give scheme, host and optional port, e.g. https://epp.example.it"
                );
            }
            if ( ! in_array($origin, $result, true)) {
                $result[] = $origin;
            }
        }
        return $result;
    }

    /**
     * Replace the whole list, and record the change to `history`.
     *
     * @param mixed[] $origins the complete new list
     * @return string[] what was stored
     * @throws \InvalidArgumentException see normalize()
     */
    public static function set(array $origins, int $userId): array {
        $desired = self::normalize($origins);

        Config::set('allowed_origins', $desired);
        History::record('allowed_origins', 0, 'update', ['allowed_origins' => $desired], $userId);

        return $desired;
    }
}
