<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `region` setting: the time zone every request and CLI command runs in,
 * and the locale amounts are formatted in (Support\Money). Shared by
 * `config region-set` and the admin-only `/v1/region`.
 *
 * @category    Net
 * @package     Eppitnic\Service\RegionSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RegionSettings
{
    public const FIELDS = ['timezone', 'lc_monetary'];

    /** @return array field => its current value */
    public static function get(): array {
        $settings = Config::get('region');
        $result = [];
        foreach (self::FIELDS as $field) {
            $result[$field] = $settings[$field] ?? null;
        }
        return $result;
    }

    /**
     * @param array $changes field => new value; every field is required
     * @return array{0: array, 1: array} [validated changes, resulting `region`]
     */
    public static function preview(array $changes): array {
        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'unknown field(s): ' . implode(', ', $unknown) .
                ' -- valid fields: ' . implode(', ', self::FIELDS)
            );
        }

        $validated = [];
        foreach ($changes as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                throw new \InvalidArgumentException("{$field} is required and cannot be unset");
            }
            $validated[$field] = $field === 'timezone'
                ? self::parseTimezone($value)
                : self::parseLocale($field, $value);
        }

        return [$validated, $validated + Config::get('region')];
    }

    /**
     * preview(), then persist and record the change to `history`.
     *
     * @throws \InvalidArgumentException on an unknown field or a failed validator
     * @return array the settings after the change (self::get()'s shape)
     */
    public static function set(array $changes, int $userId): array {
        [$validated, $result] = self::preview($changes);

        Config::set('region', $result);
        History::record('region', 0, 'update', ['changes' => $validated], $userId);

        return self::get();
    }

    /** @return string[] every time zone PHP knows, for a picker */
    public static function timezones(): array {
        return \DateTimeZone::listIdentifiers();
    }

    private static function parseTimezone(string $value): string {
        if ( ! in_array($value, self::timezones(), true)) {
            throw new \InvalidArgumentException("timezone must be a time zone name such as Europe/Rome, not '{$value}'");
        }
        return $value;
    }

    /** a locale name: C, POSIX, it_IT, it_IT.UTF-8, … */
    private static function parseLocale(string $field, string $value): string {
        $pattern = '/^(C|POSIX|[A-Za-z]{2,}(_[A-Za-z]{2})?(\.[A-Za-z0-9-]+)?(@[A-Za-z0-9]+)?)$/';
        if (strlen($value) > 64 || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException("{$field} must be a locale name such as it_IT.UTF-8, not '{$value}'");
        }
        return $value;
    }
}
