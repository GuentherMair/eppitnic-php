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
        return self::LOCAL_MUTATING_OPTIONS + [
            'force' => "skip the 'path' field's is_executable() check, where that field exists",
        ];
    }

    public function run(): int {
        $this->database();

        $job = $this->job();
        $fields = CronjobSettings::fields($job);

        $field = $this->arguments[0] ?? null;
        if ($field === null || ! in_array($field, $fields, true)) {
            throw new UsageError('give a field (' . implode(', ', $fields) . ') and, optionally, a value to set it to');
        }

        // no value = unset, falling back to the job's own default
        $value = $this->arguments[1] ?? null;
        $force = $this->hasOption('force');
        $label = "{$job}.{$field}";

        try {
            [, $preview] = CronjobSettings::preview($job, [$field => $value], $force);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $new = $preview[$field];
        $current = CronjobSettings::get($job)[$field] ?? null;

        if ($current === $new) {
            $this->line("{$label} is already " . ($new === null ? 'unset' : self::describeValue($new)));
            return 0;
        }

        $verb = $new === null ? 'Unset' : "Set to " . self::describeValue($new);
        if ( ! $this->confirm("{$verb} {$label}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($new === null ? "would unset {$label}" : "would set {$label} to " . self::describeValue($new));
            return 0;
        }

        try {
            CronjobSettings::set($job, [$field => $value], $this->userId(), $force);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("{$label} " . ($new === null ? 'unset' : 'set to ' . self::describeValue($new)), [$job => [$field => $new]]);
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
