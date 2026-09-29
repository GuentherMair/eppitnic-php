<?php

namespace Eppitnic\Persistence;

use Eppitnic\Support\PasswordPolicy;
use RedBeanPHP\R;

/**
 * Creates a local login account -- extracted from UserCreateCommand so
 * Setup\Installer makes the first admin by the same path, neither
 * re-implementing the insert or the hashing.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\User
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class User
{
    /**
     * The columns the read routes select. A plain user only ever reads their
     * own row; the address and description are added for a manager or admin,
     * so the edit dialog can show what it is about to change.
     */
    public static function readColumns(bool $isManager): string {
        $columns = 'id, active, role, reseller_id,
            (SELECT name FROM resellers r WHERE r.id = users.reseller_id) AS reseller_name,
            username, max_token_age, max_idle_time, debug, notify_enabled,
            totp_secret IS NOT NULL AS has_totp';

        return $isManager
            ? "{$columns}, description, email, must_change_password, must_enroll_mfa"
            : $columns;
    }

    /**
     * @return string|null why $role cannot be given to a user of $resellerId,
     *                     or null when it can
     */
    public static function roleError(string $role, int $resellerId): ?string {
        if ( ! in_array($role, Scope::ROLES, true)) {
            return 'role must be one of: ' . implode(', ', Scope::ROLES);
        }
        if ($role === 'admin' && $resellerId !== 1) {
            return 'an admin can only belong to reseller 1';
        }
        return null;
    }

    /**
     * Managers and admins start with notifications on, plain users off.
     *
     * @param int|null $actorId who creates it, for history (null: nobody, as
     *                 when the installer makes the first admin)
     * @param bool|null $notifyEnabled overrides that default
     * @throws UsernameTaken if $username is already in use
     * @throws \InvalidArgumentException if $password does not meet
     *                       PasswordPolicy, or $role does not fit $resellerId
     * @return int the new row's id
     */
    public static function create(
        string $username,
        string $password,
        ?string $email = null,
        ?string $description = null,
        int $resellerId = 1,
        string $role = 'user',
        bool $mustChangePassword = false,
        bool $mustEnrollMfa = false,
        ?int $actorId = null,
        bool $active = true,
        ?int $maxTokenAge = null,
        ?int $maxIdleTime = null,
        bool $debug = false,
        ?bool $notifyEnabled = null
    ): int {
        if (($error = self::roleError($role, $resellerId)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        // The last point before a password becomes an uninspectable hash, and
        // reached from the installer, the CLI and anything added later. Callers
        // still check first: this is the guarantee, not the error message
        if ( ! PasswordPolicy::isAcceptable($password)) {
            throw new \InvalidArgumentException(PasswordPolicy::explain($password));
        }

        if (R::getCell('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            throw new UsernameTaken("a user named '{$username}' already exists");
        }

        R::exec("
            INSERT INTO users (reseller_id, role, description, username, password, email, notify_enabled, active,
                               max_token_age, max_idle_time, debug, must_change_password, must_enroll_mfa)
            VALUES (:reseller_id, :role, :description, :username, :password, :email, :notify_enabled, :active,
                    :max_token_age, :max_idle_time, :debug, :must_change_password, :must_enroll_mfa)
        ", [
            ':reseller_id'    => $resellerId,
            ':role'           => $role,
            ':description'    => $description,
            ':username'       => $username,
            ':password'       => password_hash($password, PASSWORD_DEFAULT),
            ':email'          => $email,
            ':notify_enabled' => (int) ($notifyEnabled ?? $role !== 'user'),
            ':active'         => (int) $active,
            ':max_token_age'  => $maxTokenAge,
            ':max_idle_time'  => $maxIdleTime,
            ':debug'          => (int) $debug,
            ':must_change_password' => (int) $mustChangePassword,
            ':must_enroll_mfa'      => (int) $mustEnrollMfa,
        ]);

        $id = (int) R::getInsertID();
        History::record('users', $id, 'create', ['username' => $username, 'role' => $role, 'reseller_id' => $resellerId], $actorId);

        return $id;
    }
}
