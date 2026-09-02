<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\PollProcessor;
use Eppitnic\Service\RegistryPasswordChange;

/**
 * The scheduled run: drain the poll queue, reconcile transfers, then act on a
 * password reminder -- one verb because the reconcile reads what the drain
 * stored and the rotation's fresh <login> invalidates their session. Never asks.
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
        foreach (RegistryPasswordChange::rotateOnReminder() as $line) {
            $this->line($line);
        }

        return 0;
    }
}
