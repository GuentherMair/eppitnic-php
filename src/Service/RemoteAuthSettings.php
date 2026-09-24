<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `remote_auth` setting: whether Api\Auth trusts a front server's
 * REMOTE_USER (or a header from a trusted proxy) instead of a bearer token.
 * Shared by `config remote-auth-set` and the admin-only `/v1/remote-auth`.
 *
 * @category    Net
 * @package     Eppitnic\Service\RemoteAuthSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RemoteAuthSettings
{
    /** a client already controls these; trusting one would let it pick its
     *  own identity, defeating the point of delegating to a front server. */
    private const FORBIDDEN_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    /** field => required. 'header' unset (null/blank) means server mode. */
    private const FIELDS = [
        'enabled' => true,
        'header'  => false,
    ];

    /** @return string[] every field this service knows, in declared order */
    public static function fields(): array {
        return array_keys(self::FIELDS);
    }

    /** @return array field => its current value (null where unset) */
    public static function get(): array {
        $settings = Config::get('remote_auth');
        $result = [];
        foreach (self::FIELDS as $field => $required) {
            $result[$field] = $settings[$field] ?? null;
        }
        return $result;
    }

    /**
     * @param array $changes field => new value; blank/null unsets 'header'
     * @return array{0: array, 1: array} [validated changes, resulting `remote_auth`]
     */
    public static function preview(array $changes): array {
        $unknown = array_diff(array_keys($changes), self::fields());
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'unknown field(s): ' . implode(', ', $unknown) .
                ' -- valid fields: ' . implode(', ', self::fields())
            );
        }

        $validated = [];
        foreach ($changes as $field => $value) {
            $blank = $value === null || $value === '';
            if ($blank) {
                if (self::FIELDS[$field]) {
                    throw new \InvalidArgumentException("{$field} is required and cannot be unset");
                }
                $validated[$field] = null;
                continue;
            }
            $validated[$field] = $field === 'enabled'
                ? self::parseBool($field, $value)
                : self::parseHeader($field, $value);
        }

        return [$validated, $validated + Config::get('remote_auth')];
    }

    /**
     * preview(), then persist and record the change to `history`.
     *
     * @throws \InvalidArgumentException on an unknown field or a failed
     *         validator
     * @return array the settings after the change (self::get()'s shape)
     */
    public static function set(array $changes, int $userId): array {
        [$validated, $result] = self::preview($changes);

        Config::set('remote_auth', $result);
        History::record('remote_auth', 0, 'update', ['changes' => $validated], $userId);

        return self::get();
    }

    private static function parseBool(string $field, mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower((string) $value);
        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }
        throw new \InvalidArgumentException("{$field} must be true or false");
    }

    private static function parseHeader(string $field, mixed $value): string {
        $header = trim((string) $value);
        if ( ! preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $header)) {
            throw new \InvalidArgumentException("{$field} must look like an HTTP header name, e.g. X-Remote-User");
        }
        if (in_array(strtolower($header), self::FORBIDDEN_HEADERS, true)) {
            throw new \InvalidArgumentException("{$field} must not be Authorization, Cookie or Proxy-Authorization");
        }
        return $header;
    }
}
