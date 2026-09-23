<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\EppSettings;

/**
 * `config epp-set <field> [value]` over the 7 plain `epp.*` fields --
 * `server`/`server_deleted`/`port`/`interface`/`username`/`lang`/
 * `cl_trid_prefix`. See EppSettings, the single place that validates,
 * persists and audits a change here (also used by `PATCH /v1/session/epp`
 * and, for `server`'s production/test presets, `config epp-server`).
 * `password` is `config epp-password`'s alone -- a registry round-trip,
 * unlike every field here, which is a local write only.
 */
final class ConfigEppSetCommand extends Command
{
    public function describe(): string {
        return 'set one epp.* field: ' . implode(', ', EppSettings::fields());
    }

    public function arguments(): string {
        return '<field> [value]';
    }

    public function options(): array {
        // not self::MUTATING_OPTIONS verbatim -- its --dry-run wording talks
        // about the EPP request that would be sent, and this command never
        // opens a registry session at all, only writes a local setting
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $fields = EppSettings::fields();
        $field = $this->arguments[0] ?? null;
        if ($field === null || ! in_array($field, $fields, true)) {
            throw new UsageError(
                'give a field (' . implode(', ', $fields) . ') and, optionally, a value to set it to' .
                " -- see 'config epp-server' for server presets, 'config epp-password' for the password"
            );
        }

        // no value = unset, for the fields that allow it
        $value = $this->arguments[1] ?? null;
        $label = "epp.{$field}";

        try {
            [, $preview] = EppSettings::preview([$field => $value]);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $new = $preview[$field];
        $current = EppSettings::get()[$field] ?? null;

        if ($current === $new) {
            $this->line("{$label} is already " . ($new === null ? 'unset' : "'{$new}'"));
            return 0;
        }

        $verb = $new === null ? 'Unset' : "Set to '{$new}'";
        if ( ! $this->confirm("{$verb} {$label}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($new === null ? "would unset {$label}" : "would set {$label}: '{$current}' -> '{$new}'");
            return 0;
        }

        try {
            EppSettings::set([$field => $value], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("{$label} " . ($new === null ? 'unset' : "set to '{$new}'"), ['field' => $field, 'value' => $new]);
        return 0;
    }
}
