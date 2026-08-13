<?php

namespace Net\EPP\Cli;

use Net\EPP\Config;
use Net\EPP\Service\RegistryPassword;

/**
 * Settle a password rotation that did not finish.
 *
 * RegistryPassword::rotateOnReminder() writes its candidate password to the
 * `epp` setting before sending it to the registry, so a run that dies in
 * between leaves both the old and the new password on disk with no record of
 * which one the registry accepted. The cron job resolves that itself on its
 * next run -- but every EPP call fails until it does, and waiting for a cron
 * job is a poor answer when someone is standing there watching it fail.
 *
 * This is the same reconciliation, on demand. It asks the registry which
 * password is live by trying to log in with each.
 */
final class DoctorEppPasswordCommand extends Command
{
    public function describe(): string {
        return 'settle an unfinished registry password rotation';
    }

    public function run(): int {
        $this->database();

        $epp = Config::get('epp');
        if (empty($epp['pendingPassword'])) {
            $this->line('no unfinished password rotation');
            return 0;
        }

        foreach (RegistryPassword::reconcile() as $line) {
            $this->line($line);
        }

        // still there means the registry accepted neither password, which is
        // not something this command can fix
        return empty(Config::get('epp')['pendingPassword']) ? 0 : DATA_INCONSISTENT;
    }
}
