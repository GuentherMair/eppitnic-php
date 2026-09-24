<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\Notifier;

/**
 * `config smtp-set <field> [value]` over the `smtp` setting -- see
 * Notifier, the single place that validates, persists and audits a
 * change here (also used by `PATCH /v1/smtp`). `message_types` takes a
 * comma-separated list (`config smtp-set message_types a,b,c`); every
 * other field takes a single value, or none to unset it where allowed.
 */
final class ConfigSmtpSetCommand extends Command
{
    public function describe(): string {
        return 'set one smtp.* field: ' . implode(', ', Notifier::fields());
    }

    public function arguments(): string {
        return '<field> [value]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $fields = Notifier::fields();
        $field = $this->arguments[0] ?? null;
        if ($field === null || ! in_array($field, $fields, true)) {
            throw new UsageError('give a field (' . implode(', ', $fields) . ') and, optionally, a value to set it to');
        }

        $raw = $this->arguments[1] ?? null;
        $value = $field === 'message_types' && $raw !== null
            ? array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'))
            : $raw;
        $label = "smtp.{$field}";

        try {
            [, $preview] = Notifier::preview([$field => $value]);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $new = $preview[$field];
        $current = Notifier::get()[$field] ?? null;

        if ($current === $new) {
            $this->line("{$label} is already " . self::describeValue($new));
            return 0;
        }

        $verb = self::isEmpty($new) ? 'Unset' : "Set to " . self::describeValue($new);
        if ( ! $this->confirm("{$verb} {$label}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line(self::isEmpty($new)
                ? "would unset {$label}"
                : "would set {$label}: " . self::describeValue($current) . ' -> ' . self::describeValue($new));
            return 0;
        }

        try {
            Notifier::set([$field => $value], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record(
            "{$label} " . (self::isEmpty($new) ? 'unset' : 'set to ' . self::describeValue($new)),
            ['field' => $field, 'value' => $field === 'password' ? '[redacted]' : $new]
        );
        return 0;
    }

    private static function isEmpty(mixed $value): bool {
        return $value === null || $value === [];
    }

    private static function describeValue(mixed $value): string {
        if ($value === null || $value === []) {
            return 'unset';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return "'" . implode(',', $value) . "'";
        }
        return "'{$value}'";
    }
}
