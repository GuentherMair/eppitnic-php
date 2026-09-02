<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

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
        return [
            'dry-run' => 'print what would change, without writing it',
            'yes'     => 'do not ask for confirmation',
        ];
    }

    /**
     * @return string|null a validation error, or null if $value is acceptable
     */
    private function validate(string $field, string $value): ?string {
        if (trim($value) !== $value || preg_match('/\s/', $value) === 1) {
            return "{$field} must not contain whitespace";
        }

        switch ($field) {
            case 'interface':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    ? 'interface must be an IPv4 address'
                    : null;

            case 'lang':
                return in_array($value, ['it', 'en'], true) ? null : "lang must be 'it' or 'en'";

            case 'cl_trid_prefix':
                // Client::set_clTRID() appends "-{unix timestamp}-{5 chars}"
                // (17 characters) to build the full clTRID, and
                // epp:trIDStringType (xsd/epp-1.0.xsd) caps that whole string
                // at 64 -- so the prefix itself must leave room for the rest.
                return ($value !== '' && strlen($value) <= 47)
                    ? null
                    : 'cl_trid_prefix must be 1 to 47 characters';

            case 'username':
                // eppcom:clIDType (xsd/eppcom-1.0.xsd): 3 to 16 characters.
                // The '-REG' suffix is nic.it's own registrar-account
                // convention, not a schema rule, but every real account has
                // it, so a value without one is almost certainly a mistake.
                $len = strlen($value);
                if ($len < 3 || $len > 16) {
                    return 'username must be 3 to 16 characters (EPP clIDType)';
                }
                return str_ends_with($value, '-REG')
                    ? null
                    : "username must end in '-REG' (nic.it's registrar account convention)";

            default:
                return null; // unreachable -- run() already checked FIELDS
        }
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

        if ($error = $this->validate($field, $value)) {
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
