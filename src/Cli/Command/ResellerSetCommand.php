<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\ResellerService;

/**
 * `reseller set <id> <field> <value>` over name/max_operations/active --
 * ResellerService validates (incl. reseller 1 never deactivated). Unlike
 * ConfigRemoteAuthSetCommand, no field here is meaningfully unset.
 */
final class ResellerSetCommand extends Command
{
    private const FIELDS = ['name', 'max_operations', 'active'];

    public function describe(): string {
        return 'change one reseller field: ' . implode(', ', self::FIELDS);
    }

    public function arguments(): string {
        return '<id> <field> <value>';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $id = (int) ($this->arguments[0] ?? 0);
        $field = $this->arguments[1] ?? null;
        if ($id <= 0 || $field === null || ! in_array($field, self::FIELDS, true)) {
            throw new UsageError('give a reseller id, a field (' . implode(', ', self::FIELDS) . ') and a value');
        }

        $current = ResellerService::get($id);
        if ($current === null) {
            throw new UsageError("no reseller with id {$id}");
        }

        $value = $this->arguments[2] ?? null;
        if ($value === null) {
            throw new UsageError("give a value for {$field}");
        }
        $label = "reseller {$id}.{$field}";

        if ((string) $current[$field] === $value) {
            $this->line("{$label} is already '{$value}'");
            return 0;
        }

        if ( ! $this->confirm("Set {$label} to '{$value}'?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set {$label}: '{$current[$field]}' -> '{$value}'");
            return 0;
        }

        try {
            $reseller = ResellerService::update($id, [$field => $value], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("{$label} set to '{$reseller[$field]}'", $reseller);
        return 0;
    }
}
