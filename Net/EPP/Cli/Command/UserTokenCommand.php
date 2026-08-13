<?php

namespace Net\EPP\Cli\Command;

use Net\EPP\Cli\Command;
use Net\EPP\Cli\UsageError;
use Net\EPP\Persistence\Changelog;
use Net\EPP\Service\PasswordService;
use RedBeanPHP\R;

/**
 * Issue a fixed API token for an existing user, for headless or scripted
 * access.
 *
 * The same operation as POST /v1/users/{id}/api-token, run directly against
 * the database.
 */
final class UserTokenCommand extends Command
{
    public function describe(): string {
        return 'issue a fixed API token for a user';
    }

    public function arguments(): string {
        return '<username>';
    }

    public function options(): array {
        return [
            'days=' => 'validity in days from now; omitted or 0 means it never expires',
        ];
    }

    public function run(): int {
        $this->database();

        $names = $this->names();
        $username = $names[0] ?? '';
        if ($username === '') {
            throw new UsageError('give a username');
        }

        $user = R::getRow("SELECT id, username FROM users WHERE username = ? AND active = 1", [$username]);
        if (empty($user)) {
            $this->warn("no active user named '{$username}'");
            return INVALID_INPUT;
        }

        $days = (int) $this->option('days', 0);
        $expires = $days > 0 ? time() + $days * 86400 : 0;
        if ($expires === 0) {
            $this->warn('No validity period given: this token is valid until explicitly revoked'
                . ' with DELETE /v1/users/{id}/api-token.');
        }

        // 32 random bytes as an opaque hex token, stored only as a hash, the
        // same as a password: the plaintext is shown here and never again
        $token = PasswordService::token();

        R::exec("UPDATE users SET api_token = :token, api_token_expires = :expires WHERE id = :id", [
            ':token'   => hash('sha256', $token),
            ':expires' => $expires,
            ':id'      => $user['id'],
        ]);
        Changelog::record('users', (int) $user['id'], 'update', ['api_token_expires' => $expires], (int) $user['id']);

        $this->record(
            "API token issued for '{$user['username']}' (id {$user['id']})\n"
            . "  token (shown only once, store it now): {$token}\n"
            . '  expires: ' . ($expires === 0 ? 'never' : date('c', $expires)),
            [
                'id'       => (int) $user['id'],
                'username' => $user['username'],
                'token'    => $token,
                'expires'  => $expires,
            ]
        );
        return 0;
    }
}
