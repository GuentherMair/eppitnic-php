<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\ResellerService;

/**
 * `reseller create <name> [--max-operations=N]` -- see ResellerService for
 * the validation (unique name, non-negative quota).
 */
final class ResellerCreateCommand extends Command
{
    public function describe(): string {
        return 'create a reseller';
    }

    public function arguments(): string {
        return '<name>';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS + [
            'max-operations=' => 'daily cap on registrations + transfer-ins (default 0, unlimited)',
        ];
    }

    public function run(): int {
        $this->database();

        $name = $this->arguments[0] ?? '';
        if ($name === '') {
            throw new UsageError('give a name');
        }
        $maxOperations = (int) $this->option('max-operations', 0);

        $quotaText = $maxOperations > 0 ? " (quota {$maxOperations})" : '';
        if ( ! $this->confirm("Create reseller '{$name}'{$quotaText}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would create reseller '{$name}'{$quotaText}");
            return 0;
        }

        try {
            $reseller = ResellerService::create($name, $maxOperations, $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("reseller '{$reseller['name']}' created (id {$reseller['id']})", $reseller);
        return 0;
    }
}
