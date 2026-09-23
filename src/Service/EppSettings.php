<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Support\Validate;

/**
 * The 7 plain `epp.*` fields (everything but `password`/`pendingPassword`,
 * which `config epp-password`/`RegistryPasswordChange` own) -- shared by
 * `config epp-set`/`config epp-server` and `PATCH /v1/session/epp`, so
 * neither reimplements the other's validation, and both audit through the
 * same path. Field rules themselves stay in `Validate::eppField()`, also
 * used by first-run setup.
 *
 * @category    Net
 * @package     Eppitnic\Service\EppSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class EppSettings
{
    /** field => required. An optional field's blank/null value unsets it. */
    private const FIELDS = [
        'server'         => true,
        'server_deleted' => false,
        'port'           => false,
        'interface'      => false,
        'username'       => true,
        'lang'           => true,
        'cl_trid_prefix' => true,
    ];

    /** @return string[] every field this service knows, in declared order */
    public static function fields(): array {
        return array_keys(self::FIELDS);
    }

    /** @return array field => its current value (null where unset) */
    public static function get(): array {
        $epp = Config::get('epp');
        $result = [];
        foreach (self::FIELDS as $field => $required) {
            $result[$field] = $epp[$field] ?? null;
        }
        return $result;
    }

    /**
     * Validate and coerce $changes, and compute what `epp` would hold
     * afterward -- without writing anything.
     *
     * @param array $changes field => new value; blank/null unsets an
     *              optional field
     * @return array{0: array, 1: array} [validated changes, resulting `epp`]
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
            $value = $value === null ? null : trim((string) $value);
            if ($value === null || $value === '') {
                if (self::FIELDS[$field]) {
                    throw new \InvalidArgumentException("{$field} is required and cannot be unset");
                }
                $validated[$field] = null;
                continue;
            }
            if ($error = Validate::eppField($field, $value)) {
                throw new \InvalidArgumentException($error);
            }
            $validated[$field] = $field === 'port' ? (int) $value : $value;
        }

        return [$validated, $validated + Config::get('epp')];
    }

    /**
     * preview(), then persist and record the change to `history`.
     *
     * @param array $changes field => new value, or null/blank to unset an
     *              optional field. Only the given fields change.
     * @throws \InvalidArgumentException on an unknown field or a failed
     *         validator -- callers translate that into a UsageError (CLI)
     *         or a 400 (API)
     * @return array field => its value after the change (self::get()'s shape)
     */
    public static function set(array $changes, int $userId): array {
        [$validated, $result] = self::preview($changes);

        Config::set('epp', $result);
        History::record('epp', 0, 'update', ['changes' => $validated], $userId);

        return self::get();
    }
}
