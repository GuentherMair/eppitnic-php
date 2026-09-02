<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\RegistryPasswordChange;

/**
 * Change the shared EPP registry password, or -- with --force -- adopt one
 * already valid at the registry without changing it there.
 *
 * The one `epp` field that can't be a plain local write like `config
 * epp-set`'s: a mistyped `interface` fails the next EPP call with a clear
 * error, but a password this installation disagrees with the registry about
 * breaks every one of them. So this never writes a password it has not
 * itself verified against the registry first -- via RegistryPasswordChange,
 * the same machinery `poll process`'s automatic rotation and `doctor
 * epp-password`'s recovery already use.
 *
 * Cannot go through withSession(): the change (or the verification, for
 * --force) is carried by the EPP <login> command itself, so it has to *be*
 * the login, not something done inside a session already logged in.
 */
final class ConfigEppPasswordCommand extends Command
{
    public function describe(): string {
        return 'change the shared EPP registry password, or --force to adopt one already valid there';
    }

    public function arguments(): string {
        return '<new-password>';
    }

    public function options(): array {
        // not self::MUTATING_OPTIONS verbatim -- its --dry-run wording
        // promises the real EPP XML domain/contact commands print; this
        // never opens a session through withSession(), so it can only
        // describe what it would do, not show the actual <login>
        return [
            'force'   => 'do not change the password at the registry -- verify the given value is already ' .
                'accepted there, and adopt it locally as-is',
            'dry-run' => 'describe what would happen (change, or verify-and-adopt), without doing it',
            'yes'     => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        $this->database();

        $password = $this->arguments[0] ?? null;
        if ($password === null) {
            throw new UsageError('give the new password');
        }

        // epp:pwType (xsd/epp-1.0.xsd): 6 to 16 characters, for both <pw>
        // and <newPW> -- the registry's own ceiling, not this codebase's
        // PasswordPolicy, which governs local admin-account passwords only
        $len = strlen($password);
        if ($len < 6 || $len > 16) {
            throw new UsageError('password must be 6 to 16 characters (EPP pwType)');
        }

        $force = $this->hasOption('force');
        $verb = $force ? 'Adopt (verify only, no registry change to)' : 'Change';
        if ( ! $this->confirm("{$verb} the shared EPP registry password?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($force
                ? 'would verify the given password against the registry and adopt it, without changing it there'
                : 'would change the registry password to the given value');
            return 0;
        }

        $outcome = $force
            ? RegistryPasswordChange::adopt($password)
            : RegistryPasswordChange::apply($password, true);

        if ( ! $outcome['ok']) {
            $this->warn($outcome['error']);
            return CHANGE_PASSWORD_FAILED;
        }

        $epp = Config::get('epp');
        $this->record('registry password ' . ($force ? 'adopted' : 'changed'), [
            'password_set'       => ($epp['password'] ?? '') !== '',
            'rotation_pending'   => ($epp['pendingPassword'] ?? '') !== '',
            'lastPasswordUpdate' => $epp['lastPasswordUpdate'] ?? 0,
        ]);
        return 0;
    }
}
