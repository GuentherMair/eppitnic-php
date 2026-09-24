<?php

namespace Eppitnic\Service;

use Eppitnic\Persistence\History;
use RedBeanPHP\R;

/**
 * A reseller: listing with its counts, creation, and the fields an admin may
 * change (name, max_operations, active). Never deleted -- deactivated
 * instead -- and reseller 1, the registrar itself, can never be deactivated.
 *
 * @category    Net
 * @package     Eppitnic\Service\ResellerService
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ResellerService
{
    private const MAX_NAME = 64;
    private const FIELDS = ['name', 'max_operations', 'active'];

    private const SELECT = "id, name, max_operations, active, creation_time,
        (SELECT COUNT(*) FROM users    u WHERE u.reseller_id = resellers.id) AS users,
        (SELECT COUNT(*) FROM domains  d WHERE d.reseller_id = resellers.id) AS domains,
        (SELECT COUNT(*) FROM contacts c WHERE c.reseller_id = resellers.id) AS contacts";

    /**
     * @return array<int, array{id: int, name: string, max_operations: int,
     *         active: int, creation_time: string, users: int, domains: int,
     *         contacts: int}>
     */
    public static function list(): array {
        return array_map(self::cast(...), R::getAll("SELECT " . self::SELECT . " FROM resellers ORDER BY id"));
    }

    /**
     * @return array{id: int, name: string, max_operations: int, active: int,
     *         creation_time: string, users: int, domains: int, contacts: int}|null
     */
    public static function get(int $id): ?array {
        $row = R::getRow("SELECT " . self::SELECT . " FROM resellers WHERE id = :id", [':id' => $id]);
        return empty($row) ? null : self::cast($row);
    }

    /**
     * @throws \InvalidArgumentException on an invalid name or max_operations
     */
    public static function create(string $name, int $maxOperations, int $actorId): array {
        $name = self::validateName($name, null);
        $maxOperations = self::validateMaxOperations($maxOperations);

        R::exec("INSERT INTO resellers (name, max_operations) VALUES (:name, :max_operations)", [
            ':name' => $name, ':max_operations' => $maxOperations,
        ]);
        $id = (int) R::getInsertID();

        History::record('resellers', $id, 'create', ['name' => $name, 'max_operations' => $maxOperations], $actorId);

        return self::get($id);
    }

    /**
     * @param array<string, mixed> $changes any of name, max_operations, active
     * @throws \InvalidArgumentException on an unknown field, an invalid value,
     *         a missing reseller, or deactivating reseller 1
     */
    public static function update(int $id, array $changes, int $actorId): array {
        $current = self::get($id);
        if ($current === null) {
            throw new \InvalidArgumentException("reseller {$id} not found");
        }

        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('unknown field(s): ' . implode(', ', $unknown));
        }

        $fields = [];
        if (array_key_exists('name', $changes)) {
            $fields['name'] = self::validateName((string) $changes['name'], $id);
        }
        if (array_key_exists('max_operations', $changes)) {
            $fields['max_operations'] = self::validateMaxOperations($changes['max_operations']);
        }
        if (array_key_exists('active', $changes)) {
            $active = self::parseBool($changes['active']);
            if ($id === 1 && ! $active) {
                throw new \InvalidArgumentException('reseller 1 (the registrar itself) cannot be deactivated');
            }
            $fields['active'] = $active ? 1 : 0;
        }

        if ($fields !== []) {
            $set = [];
            $bind = [':id' => $id];
            foreach ($fields as $column => $value) {
                $set[] = "{$column} = :{$column}";
                $bind[":{$column}"] = $value;
            }
            R::exec("UPDATE resellers SET " . implode(', ', $set) . " WHERE id = :id", $bind);
            History::record('resellers', $id, 'update', $fields, $actorId);
        }

        return self::get($id);
    }

    private static function validateName(string $name, ?int $ignoreId): string {
        $name = trim($name);
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME) {
            throw new \InvalidArgumentException('name must be 1-' . self::MAX_NAME . ' characters');
        }

        $sql = 'SELECT COUNT(*) FROM resellers WHERE LOWER(name) = LOWER(:name)';
        $bind = [':name' => $name];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $bind[':id'] = $ignoreId;
        }
        if ((int) R::getCell($sql, $bind) > 0) {
            throw new \InvalidArgumentException("a reseller named '{$name}' already exists");
        }

        return $name;
    }

    private static function validateMaxOperations(mixed $value): int {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);
        } else {
            throw new \InvalidArgumentException('max_operations must be a non-negative integer');
        }
        if ($int < 0) {
            throw new \InvalidArgumentException('max_operations must be a non-negative integer');
        }
        return $int;
    }

    private static function parseBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }
        throw new \InvalidArgumentException('active must be a boolean');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array {
        foreach (['id', 'max_operations', 'active', 'users', 'domains', 'contacts'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        return $row;
    }
}
