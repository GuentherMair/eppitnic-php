<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Support\Money;

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

        // the text for people, in region.lc_monetary; --json keeps the bare number
        $this->record(Money::euro($credit), ['credit' => $credit]);
        return 0;
    }
}
