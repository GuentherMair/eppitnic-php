<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\Changelog;
use RedBeanPHP\R;

/**
 * Create a local login account.
 *
 * The way to make the first admin: POST /v1/users needs an admin token to
 * call, so there is no way to bootstrap one over the API.
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

        if (R::getCell("SELECT id FROM users WHERE username = ?", [$username]) !== null) {
            $this->warn("a user named '{$username}' already exists");
            return INVALID_INPUT;
        }

        $isAdmin = $this->hasOption('admin');

        R::exec("
            INSERT INTO users (description, username, password, email, max_operations, active, admin)
            VALUES (:description, :username, :password, :email, :max_operations, 1, :admin)
        ", [
            ':description'    => $this->option('description'),
            ':username'       => $username,
            ':password'       => password_hash((string) $this->option('password'), PASSWORD_DEFAULT),
            ':email'          => $this->option('email'),
            ':max_operations' => (int) $this->option('max-operations', 0),
            ':admin'          => $isAdmin ? 1 : 0,
        ]);

        $id = (int) R::getInsertID();
        // no authenticated actor exists yet when bootstrapping, so the new user
        // is recorded as its own actor
        Changelog::record('users', $id, 'create', ['username' => $username, 'admin' => $isAdmin], $id);

        $this->record(
            "user '{$username}' created (id {$id}" . ($isAdmin ? ', admin' : '') . ')',
            ['id' => $id, 'username' => $username, 'admin' => $isAdmin]
        );
        return 0;
    }
}
