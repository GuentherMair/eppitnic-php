<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UsernameTaken;

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
            'max-operations=' => 'daily domain-create quota; 0 (the default) is unlimited',
            'admin'           => 'grant admin (unrestricted) access',
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

        $isAdmin = $this->hasOption('admin');

        try {
            $id = User::create(
                username: $username,
                password: (string) $this->option('password'),
                email: $this->option('email'),
                description: $this->option('description'),
                maxOperations: (int) $this->option('max-operations', 0),
                admin: $isAdmin,
            );
        } catch (UsernameTaken $e) {
            $this->warn($e->getMessage());
            return INVALID_INPUT;
        }

        $this->record(
            "user '{$username}' created (id {$id}" . ($isAdmin ? ', admin' : '') . ')',
            ['id' => $id, 'username' => $username, 'admin' => $isAdmin]
        );
        return 0;
    }
}
