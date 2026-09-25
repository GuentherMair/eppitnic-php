<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Config;
use Eppitnic\Service\RegistryPasswordChange;

/**
 * Settle a password rotation that did not finish: the candidate is written
 * before it is sent, so a run dying between leaves both on disk. The cron job
 * resolves it eventually; this is the same reconciliation, on demand.
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

        foreach (RegistryPasswordChange::reconcile($this->userId()) as $line) {
            $this->line($line);
        }

        // still there means the registry accepted neither password, which is
        // not something this command can fix
        return empty(Config::get('epp')['pendingPassword']) ? 0 : DATA_INCONSISTENT;
    }
}
