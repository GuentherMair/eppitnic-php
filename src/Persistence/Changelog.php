<?php

namespace Eppitnic\Persistence;

use RedBeanPHP\R;

/**
 * The audit trail: who changed what, and when.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\Changelog
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Changelog
{
    // -----------------------------------------------------------------
    // changelog
    // -----------------------------------------------------------------

    /**
     * record an audit-trail entry for a create/update/delete
     *
     * @param string $object contacts/domains/users
     * @param int $object_id the object's DB row id
     * @param string $action create/update/delete
     * @param array $data changed fields (or a minimal identifying set, for create/delete)
     * @param int|null $user_id acting user
     */
    public static function record(string $object, int $object_id, string $action, array $data, ?int $user_id): void {
        R::exec("
            INSERT INTO changelog (user_id, object, object_id, action, data)
            VALUES (:user_id, :object, :object_id, :action, :data)
        ", [
            ':user_id'   => $user_id,
            ':object'    => $object,
            ':object_id' => $object_id,
            ':action'    => $action,
            ':data'      => json_encode($data),
        ]);
    }
}
