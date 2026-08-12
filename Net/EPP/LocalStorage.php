<?php

namespace Net\EPP;

use RedBeanPHP\R;

/**
 * The parts of local persistence that Contact and Domain do identically.
 *
 * Deliberately primitives rather than finished methods: the two classes really
 * do store differently -- a contact is upserted because `domains`.`registrant`
 * is a foreign key onto it, a domain is replaced outright, and only a domain
 * queues DNS-sync work -- so a single storeDB() covering both would be a
 * parameter list describing which of the two it was pretending to be.
 *
 * What repeated verbatim was the plumbing: appending the user-scoping clause,
 * turning a SQL failure into setError() plus false, and looking up the row id
 * for the changelog. That is what lives here.
 *
 * @category    Net
 * @package     Net\EPP\LocalStorage
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
trait LocalStorage
{
    /**
     * @return string the table this object is stored in
     */
    abstract protected static function storageTable(): string;

    /**
     * @return string the column holding the object's own identifier
     */
    abstract protected static function storageKeyColumn(): string;

    /**
     * @return string what to call the object in an error message
     */
    abstract protected static function storageNoun(): string;

    /**
     * Restrict a statement to one user's rows, unless acting as an admin.
     *
     * The placeholder is a parameter because an UPDATE already binds the key
     * as :user_id in some statements, and re-binding the same name with a
     * different value is a bug that only shows up on the rows it silently
     * fails to match.
     *
     * @param array $params bound parameters, added to in place
     * @return string the SQL to append
     */
    private function storageScope(array &$params, int $userId, bool $isAdmin, string $placeholder = ':acl_user_id'): string {
        if ($isAdmin) {
            return '';
        }
        $params[$placeholder] = $userId;
        return " AND user_id = {$placeholder}";
    }

    /**
     * Run a write, reporting a SQL failure the way every caller expects.
     *
     * @param string $action what failed, for the message ('deactivate', 'store', ...)
     * @return bool false with the error set, true on success
     */
    private function storageWrite(string $sql, array $params, string $action, string $key): bool {
        try {
            R::exec($sql, $params);
        } catch (\RedBeanPHP\RedException\SQL $e) {
            $this->setError(
                "unable to {$action} " . static::storageNoun() . " '{$key}': " . $e->getMessage()
            );
            return false;
        }
        return true;
    }

    /**
     * @return int the row's own id, which the changelog references
     */
    private function storageId(string $key): int {
        return (int) R::getCell(
            'SELECT id FROM ' . static::storageTable() . ' WHERE ' . static::storageKeyColumn() . ' = ?',
            [$key]
        );
    }

    /**
     * One row, scoped to the user unless acting as an admin.
     *
     * @return array|null the row, or null when it does not exist or is not theirs
     */
    private function storageFind(string $key, int $userId, bool $isAdmin): ?array {
        $params = [':key' => $key];
        $sql = 'SELECT * FROM ' . static::storageTable()
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $this->storageScope($params, $userId, $isAdmin);

        $row = R::getRow($sql, $params);
        return empty($row) ? null : $row;
    }

    /**
     * Copy a row onto this object's declared properties.
     *
     * Columns with no matching property are skipped, which is what keeps
     * bookkeeping columns -- id, active, last_invoice -- out of the object.
     *
     * @param string[] $serialized columns stored with serialize()
     */
    private function storageHydrate(array $row, array $serialized): void {
        foreach ($row as $column => $value) {
            $column = strtolower($column);

            if (in_array($column, $serialized, true)) {
                $this->$column = empty($value) ? [] : unserialize($value);
            } elseif (property_exists($this, $column)) {
                $this->$column = $value;
            }
        }
    }

    /**
     * The soft delete both classes use: rows are deactivated, never removed,
     * because `domains`.`registrant` is a foreign key onto `contacts`.`handle`
     * and a delete on either side would be refused.
     *
     * @param int $active 0 to deactivate, 1 to restore
     * @param string $logAction the changelog action; a restore logs as 'update',
     *               since the changelog's enum has no 'restore'
     * @param string $extraWhere an additional condition, e.g. the check that a
     *               contact is not still some domain's registrant
     */
    private function storageSetActive(
        string $key,
        int $active,
        int $userId,
        bool $isAdmin,
        string $logAction,
        array $logData,
        string $extraWhere = '',
        array $extraParams = []
    ): bool {
        $params = [':key' => $key] + $extraParams;
        $sql = 'UPDATE ' . static::storageTable() . ' SET active = ' . $active
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $extraWhere
             . $this->storageScope($params, $userId, $isAdmin);

        if ( ! $this->storageWrite($sql, $params, $active === 0 ? 'deactivate' : 'activate', $key)) {
            return false;
        }

        Helpers::logChanges(static::storageTable(), $this->storageId($key), $logAction, $logData, $userId);
        return true;
    }

    /**
     * Write changed columns back, scoped to the user unless acting as an admin.
     *
     * Writes only. The changelog entry is the caller's, because what it should
     * say differs: an update records the changed columns, while the update half
     * of an upsert records that the object was stored.
     *
     * @param array $data column => value
     */
    private function storageUpdate(string $key, array $data, int $userId, bool $isAdmin): bool {
        $set = [];
        $params = [':key' => $key];
        foreach ($data as $column => $value) {
            $set[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        $sql = 'UPDATE ' . static::storageTable() . ' SET ' . implode(', ', $set)
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $this->storageScope($params, $userId, $isAdmin);

        return $this->storageWrite($sql, $params, 'update', $key);
    }

    /**
     * Insert a row. The caller has already decided what to do about one that
     * exists -- the two classes answer that differently.
     *
     * @param array $data column => value
     */
    private function storageInsert(array $data, string $key): bool {
        $params = [];
        foreach ($data as $column => $value) {
            $params[":{$column}"] = $value;
        }

        $sql = 'INSERT INTO ' . static::storageTable()
             . ' (' . implode(', ', array_keys($data)) . ')'
             . ' VALUES (' . implode(', ', array_keys($params)) . ')';

        return $this->storageWrite($sql, $params, 'store', $key);
    }
}
