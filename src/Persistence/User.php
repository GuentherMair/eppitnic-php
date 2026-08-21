<?php

namespace Eppitnic\Persistence;

use Eppitnic\Support\PasswordPolicy;
use RedBeanPHP\R;

/**
 * Creates a local login account.
 *
 * Extracted from UserCreateCommand's direct insert so Setup\Installer can
 * create the first admin account through the same path -- once
 * config/config.php exists, `eppitnic user create` is the only other place
 * that does this, and neither should re-implement the insert or the hashing.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\User
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class User
{
    /**
     * @throws UsernameTaken if $username is already in use
     * @throws \InvalidArgumentException if $password does not meet PasswordPolicy
     * @return int the new row's id
     */
    public static function create(
        string $username,
        string $password,
        ?string $email = null,
        ?string $description = null,
        int $maxOperations = 0,
        bool $admin = false
    ): int {
        // Here rather than only in the callers: this is the last point before
        // a password becomes a hash nobody can inspect afterwards, and it is
        // reached from the installer, the CLI and anything added later. The
        // callers still check first, so they can say so in their own terms --
        // this is the guarantee, not the error message.
        if ( ! PasswordPolicy::isAcceptable($password)) {
            throw new \InvalidArgumentException(PasswordPolicy::explain($password));
        }

        if (R::getCell('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            throw new UsernameTaken("a user named '{$username}' already exists");
        }

        R::exec("
            INSERT INTO users (description, username, password, email, max_operations, active, admin)
            VALUES (:description, :username, :password, :email, :max_operations, 1, :admin)
        ", [
            ':description'    => $description,
            ':username'       => $username,
            ':password'       => password_hash($password, PASSWORD_DEFAULT),
            ':email'          => $email,
            ':max_operations' => $maxOperations,
            ':admin'          => $admin ? 1 : 0,
        ]);

        $id = (int) R::getInsertID();
        // no authenticated actor exists yet when bootstrapping, so the new
        // user is recorded as its own actor
        History::record('users', $id, 'create', ['username' => $username, 'admin' => $admin], $id);

        return $id;
    }
}
