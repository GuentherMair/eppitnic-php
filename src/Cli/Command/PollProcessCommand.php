<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\PollProcessor;
use Eppitnic\Service\RegistryPassword;

/**
 * The scheduled registry run: drain the poll queue, reconcile transfer state
 * against it, then act on any password reminder it turned up.
 *
 * None of this can wait for a user to open the API -- it has to happen on a
 * schedule regardless of whether anyone is looking. Suggested crontab entry,
 * every five minutes:
 *
 *   0-59/5 * * * *  /path/to/bin/eppitnic poll process >> /var/log/eppitnic/poll-queue.log 2>&1
 *
 * The three steps are one verb rather than three because their order is not
 * incidental. The reconcile reads what the drain stored, and the rotation must
 * come last and outside the session the first two share: EPP carries a new
 * password in the <login> command itself, so rotating means logging in again,
 * which invalidates the credential the earlier steps were using. A crontab
 * built from three separate verbs would put that ordering in the operator's
 * hands, where it is one edit away from being wrong.
 *
 * Unlike `poll drain`, this does not ask before acknowledging messages. It is
 * the scheduled job: there is nobody at the other end to ask, and refusing to
 * run unattended would defeat the point.
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
        ];
    }

    public function run(): int {
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
        foreach (RegistryPassword::rotateOnReminder() as $line) {
            $this->line($line);
        }

        return 0;
    }
}
