<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UsernameTaken;
use RedBeanPHP\R;

/**
 * Create a local login account. Once config/config.php exists this is the only
 * way to make further ones: `eppitnic setup` and the installers only ever
 * create the first.
 */
final class UserCreateCommand extends Command
{
    public function describe(): string {
        return 'create a local login account';
    }

    public function arguments(): string {
        return '<username>';
    }

    public function options(): array {
        return [
            'password='       => 'plaintext password, hashed before storing (required)',
            'email='          => 'contact e-mail address',
            'description='    => 'free-text description',
            'role='           => 'admin, manager or user (default: user); admin only in reseller 1',
            'reseller='       => 'the reseller id the user belongs to, fixed from now on (default: 1)',
        ];
    }

    public function run(): int {
        $this->database();

        $names = $this->names();
        $username = $names[0] ?? '';
        if ($username === '') {
            throw new UsageError('give a username');
        }
        if ( ! $this->hasOption('password')) {
            throw new UsageError('--password is required');
        }

        $role = (string) $this->option('role', 'user');
        $resellerId = (int) $this->option('reseller', 1);
        if (($error = User::roleError($role, $resellerId)) !== null) {
            throw new UsageError($error);
        }
        if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
            throw new UsageError("no reseller with id {$resellerId}");
        }

        try {
            $id = User::create(
                username: $username,
                password: (string) $this->option('password'),
                email: $this->option('email'),
                description: $this->option('description'),
                resellerId: $resellerId,
                role: $role,
            );
        } catch (UsernameTaken $e) {
            $this->warn($e->getMessage());
            return INVALID_INPUT;
        }

        $this->record(
            "user '{$username}' created (id {$id}, {$role} of reseller {$resellerId})",
            ['id' => $id, 'username' => $username, 'role' => $role, 'reseller_id' => $resellerId]
        );
        return 0;
    }
}
