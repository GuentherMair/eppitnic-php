<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Service\PollProcessor;
use Eppitnic\Service\RegistryPasswordChange;

/**
 * The scheduled run: drain the poll queue, reconcile transfers, then act on a
 * password reminder -- one verb because the reconcile reads what the drain
 * stored and the rotation's fresh <login> invalidates their session. Never
 * asks. A no-op while `poll_process.enabled` is off -- see
 * `config poll-process-set enabled true`; off is a real foot-gun (the
 * registry password stops rotating), but the operator's call to make.
 *
 *   0-59/5 * * * *  /path/to/bin/eppitnic poll process >> /var/log/eppitnic/poll-queue.log 2>&1
 */
final class PollProcessCommand extends Command
{
    public function describe(): string {
        return 'scheduled run: drain the queue, reconcile transfers, rotate the password';
    }

    public function options(): array {
        return [
            'no-rotate'    => 'skip the password rotation step',
            'no-transfers' => 'skip the transfer reconciliation step',
            // declared (rather than left to GLOBAL_OPTIONS' generic "unknown
            // option") so run()'s own rejection message, explaining why, is
            // what a caller actually sees
            'dry-run'      => 'rejected -- see the error message',
        ];
    }

    public function run(): int {
        $this->database();

        if ( ! CronjobSettings::get('poll_process')['enabled']) {
            $this->line('poll process is off (see: eppitnic config poll-process-set enabled true)');
            return 0;
        }

        if ($this->isDryRun()) {
            throw new UsageError(
                '--dry-run does not apply to poll process: what it would send depends on'
                . ' what the queue holds, which only the registry can say'
            );
        }

        $this->withSession(function ($nic, $session) {
            $processor = new PollProcessor($nic);

            foreach ($processor->drainQueue($session) as $line) {
                $this->line($line);
            }

            if ($this->hasOption('no-transfers')) {
                $this->line('transfer reconciliation skipped (--no-transfers)');
                return;
            }
            foreach ($processor->verifyTransfer() as $line) {
                $this->line($line);
            }
        });

        // Outside the session above, and last -- see the note on the class.
        // The drain may have just stored the passwdReminder this acts on.
        if ($this->hasOption('no-rotate')) {
            $this->line('password rotation skipped (--no-rotate)');
            return 0;
        }
        foreach (RegistryPasswordChange::rotateOnReminder() as $line) {
            $this->line($line);
        }

        return 0;
    }
}
