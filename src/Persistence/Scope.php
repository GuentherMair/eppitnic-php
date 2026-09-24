<?php

namespace Eppitnic\Persistence;

/**
 * Who is acting, and whose objects they may touch: contacts, domains and
 * transfers belong to a reseller, while `history` records the person. An
 * admin (or the CLI operator) reaches every reseller's objects.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\Scope
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Scope
{
    public const ROLES = ['admin', 'manager', 'user'];

    public function __construct(
        public readonly int $userId,
        public readonly int $resellerId,
        public readonly string $role,
    ) {
        if ( ! in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("unknown role '{$role}'");
        }
    }

    /** The CLI: an operator with shell access acts unrestricted, as $userId. */
    public static function operator(int $userId): self {
        return new self($userId, 1, 'admin');
    }

    public function isAdmin(): bool {
        return $this->role === 'admin';
    }

    /** manager or admin: may manage users */
    public function isManager(): bool {
        return $this->role !== 'user';
    }
}
