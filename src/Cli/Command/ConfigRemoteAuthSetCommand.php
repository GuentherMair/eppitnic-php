<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\RemoteAuthSettings;

/**
 * `config remote-auth-set <field> [value]` over the `remote_auth` setting --
 * see RemoteAuthSettings, the single place that validates, persists and
 * audits a change here (also used by `PATCH /v1/remote-auth`).
 */
final class ConfigRemoteAuthSetCommand extends Command
{
    public function describe(): string {
        return 'set one remote_auth.* field: ' . implode(', ', RemoteAuthSettings::fields());
    }

    public function arguments(): string {
        return '<field> [value]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $fields = RemoteAuthSettings::fields();
        $field = $this->arguments[0] ?? null;
        if ($field === null || ! in_array($field, $fields, true)) {
            throw new UsageError('give a field (' . implode(', ', $fields) . ') and, optionally, a value to set it to');
        }

        $value = $this->arguments[1] ?? null;
        $label = "remote_auth.{$field}";

        try {
            [, $preview] = RemoteAuthSettings::preview([$field => $value]);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $new = $preview[$field];
        $current = RemoteAuthSettings::get()[$field] ?? null;

        if ($current === $new) {
            $this->line("{$label} is already " . self::describeValue($new));
            return 0;
        }

        $verb = $new === null ? 'Unset' : "Set to " . self::describeValue($new);
        if ( ! $this->confirm("{$verb} {$label}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($new === null
                ? "would unset {$label}"
                : "would set {$label}: " . self::describeValue($current) . ' -> ' . self::describeValue($new));
            return 0;
        }

        try {
            RemoteAuthSettings::set([$field => $value], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record(
            "{$label} " . ($new === null ? 'unset' : 'set to ' . self::describeValue($new)),
            ['field' => $field, 'value' => $new]
        );
        return 0;
    }

    private static function describeValue(mixed $value): string {
        if ($value === null) {
            return 'unset';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return "'{$value}'";
    }
}
