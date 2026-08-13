<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;

/**
 * The account's remaining credit, which the registry reports as an extension
 * on login and logout rather than as a command of its own.
 */
final class SessionCreditCommand extends Command
{
    public function describe(): string {
        return 'show the remaining registry credit';
    }

    public function run(): int {
        $credit = $this->withSession(fn($nic, $session) => $session->showCredit());

        if ($credit === null) {
            $this->warn('the registry did not report a credit balance');
            return LOGIN_FAILED;
        }

        $this->record(sprintf('%.2f EUR', $credit), ['credit' => $credit]);
        return 0;
    }
}
