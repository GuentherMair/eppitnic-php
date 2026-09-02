<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Support\Validate;

/**
 * Set one plain `epp` field: interface, lang, cl_trid_prefix, or username.
 *
 * Local settings write only, no registry session opens -- same as `config
 * epp-server`. `server`/`server_deleted`/`port` have their own verb (`config
 * epp-server`); `password` has its own, guarded one (`config epp-password`),
 * since a bad value there breaks every subsequent EPP call rather than
 * failing a single request the way a bad `interface` or `cl_trid_prefix`
 * would.
 */
final class ConfigEppSetCommand extends Command
{
    private const FIELDS = ['interface', 'lang', 'cl_trid_prefix', 'username'];

    public function describe(): string {
        return 'set one epp.* field: interface, lang, cl_trid_prefix, or username';
    }

    public function arguments(): string {
        return '<field> <value>';
    }

    public function options(): array {
        // not self::MUTATING_OPTIONS verbatim -- its --dry-run wording talks
        // about the EPP request that would be sent, and this command never
        // opens a registry session at all, only writes a local setting
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $field = $this->arguments[0] ?? null;
        $value = $this->arguments[1] ?? null;
        if ($field === null || $value === null) {
            throw new UsageError('give a field (' . implode(', ', self::FIELDS) . ') and a value');
        }
        if ( ! in_array($field, self::FIELDS, true)) {
            throw new UsageError(
                "field must be one of: " . implode(', ', self::FIELDS) .
                " -- see 'config epp-server' for server/port, 'config epp-password' for the password"
            );
        }

        // the registry's own rules, shared with first-run setup rather than
        // stated twice -- see Support\Validate::eppField()
        if ($error = Validate::eppField($field, $value)) {
            throw new UsageError($error);
        }

        $epp = Config::get('epp');
        $current = (string) ($epp[$field] ?? '');

        if ($current === $value) {
            $this->line("epp.{$field} is already '{$value}'");
            return 0;
        }

        if ( ! $this->confirm("Set epp.{$field} to '{$value}'?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set epp.{$field}: '{$current}' -> '{$value}'");
            return 0;
        }

        $epp[$field] = $value;
        Config::set('epp', $epp);

        $this->record("epp.{$field} set to '{$value}'", ['field' => $field, 'value' => $value]);
        return 0;
    }
}
