<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Service\ResellerService;

/**
 * Every reseller with its counts. Reads only.
 */
final class ResellerListCommand extends Command
{
    public function describe(): string {
        return 'list resellers';
    }

    public function run(): int {
        $this->database();

        foreach (ResellerService::list() as $reseller) {
            $quota = $reseller['max_operations'] === 0 ? 'unlimited' : (string) $reseller['max_operations'];
            $this->record(
                sprintf(
                    '%-4d %-24s quota=%-10s %-8s users=%-4d domains=%-4d contacts=%-4d',
                    $reseller['id'],
                    $reseller['name'],
                    $quota,
                    $reseller['active'] ? 'active' : 'inactive',
                    $reseller['users'],
                    $reseller['domains'],
                    $reseller['contacts'],
                ),
                $reseller,
            );
        }

        return 0;
    }
}
