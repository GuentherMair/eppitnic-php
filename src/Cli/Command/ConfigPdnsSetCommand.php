<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Set or unset one `pdnsutil_*` setting: path, or ttl -- a local write only,
 * same shape as `config epp-set`. Unset (no value given) falls back to
 * `pdns sync`'s own defaults: PATH lookup for `path`, 3600s for `ttl`.
 */
final class ConfigPdnsSetCommand extends Command
{
    private const FIELDS = ['path', 'ttl'];
    private const SETTING_KEYS = ['path' => 'pdnsutil_path', 'ttl' => 'pdnsutil_ttl'];

    public function describe(): string {
        return 'set or unset a pdnsutil_* setting: path, or ttl';
    }

    public function arguments(): string {
        return '<field> [value]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS + [
            'force' => "skip the is_executable() check on a given 'path'",
        ];
    }

    public function run(): int {
        $this->database();

        $field = $this->arguments[0] ?? null;
        if ($field === null || ! in_array($field, self::FIELDS, true)) {
            throw new UsageError('give a field (' . implode(', ', self::FIELDS) . ') and, optionally, a value to set it to');
        }

        // no value = unset, falling back to pdns sync's own defaults
        $value = $this->arguments[1] ?? null;
        $key = self::SETTING_KEYS[$field];

        if ($value !== null) {
            if ($field === 'path' && ! $this->hasOption('force') && ! is_executable($value)) {
                throw new UsageError("'{$value}' is not executable -- pass --force to set it anyway");
            }
            if ($field === 'ttl') {
                if ( ! ctype_digit($value) || (int) $value < 1) {
                    throw new UsageError('ttl must be a positive whole number of seconds');
                }
                $value = (int) $value;
            }
        }

        $current = Config::get($key);
        if ($current === $value) {
            $this->line("{$key} is already " . ($value === null ? 'unset' : "'{$value}'"));
            return 0;
        }

        $verb = $value === null ? 'Unset' : "Set to '{$value}'";
        if ( ! $this->confirm("{$verb} {$key}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($value === null ? "would unset {$key}" : "would set {$key} to '{$value}'");
            return 0;
        }

        Config::set($key, $value);

        $this->record("{$key} " . ($value === null ? 'unset' : "set to '{$value}'"), [$key => $value]);
        return 0;
    }
}
