<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\CronjobSettings;

/**
 * `config <job>-set <field> [value]`, shared by every scheduled job's
 * settings command -- see CronjobSettings, the single place that knows
 * each job's fields and validators (also used by `PATCH /v1/cronjobs/
 * {job}`, so neither this nor the API route re-implements the other's
 * validation). A subcommand names only its own job and describe() text.
 */
abstract class AbstractCronjobSetCommand extends Command
{
    abstract protected function job(): string;

    public function arguments(): string {
        return '<field> [value]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $job = $this->job();
        $fields = CronjobSettings::scalarFields($job);

        $field = $this->arguments[0] ?? null;
        if ($field !== null && ! in_array($field, $fields, true)) {
            $via = CronjobSettings::listFieldCommand($job, $field);
            if ($via !== null) {
                throw new UsageError("{$job}.{$field} is edited via '{$via}', not this command");
            }
        }
        if ($field === null || ! in_array($field, $fields, true)) {
            throw new UsageError('give a field (' . implode(', ', $fields) . ') and, optionally, a value to set it to');
        }

        // no value = back to the job's default
        $value = $this->arguments[1] ?? null;
        $label = "{$job}.{$field}";

        try {
            [, $preview] = CronjobSettings::preview($job, [$field => $value]);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $new = $preview[$field];
        $current = CronjobSettings::get($job)[$field] ?? null;

        if ($current === $new) {
            $this->line("{$label} is already " . self::describeValue($new));
            return 0;
        }

        $reset = $value === null;
        $verb = ($reset ? 'Reset to default ' : 'Set to ') . self::describeValue($new);
        if ( ! $this->confirm("{$verb} {$label}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would " . ($reset ? 'reset' : 'set') . " {$label} to " . self::describeValue($new));
            return 0;
        }

        try {
            CronjobSettings::set($job, [$field => $value], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("{$label} " . ($reset ? 'reset to default ' : 'set to ') . self::describeValue($new), [$job => [$field => $new]]);
        return 0;
    }

    /** bool prints as true/false, not 1/(empty) -- everything else quoted */
    private static function describeValue(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return "'{$value}'";
    }
}
