<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The one place that knows each scheduled job's settings key, its editable
 * fields and their validators -- shared by every `config *-set` CLI command
 * and `PATCH /v1/cronjobs/{job}`, so neither reimplements the other's
 * validation or forgets to audit a change. `set()` is the only way either
 * caller writes one of these settings.
 *
 * @category    Net
 * @package     Eppitnic\Service\CronjobSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class CronjobSettings
{
    /**
     * job => [settingsKey, [field => validator]]. `keepalive`'s settingsKey
     * is null: it is a bare bool, not an object, so get()/set() special-case
     * it rather than forcing it into a shape it was never given.
     *
     * validators: 'bool', 'positive-int', 'range:MIN,MAX', 'executable-path'.
     */
    private const JOBS = [
        'pdns' => ['pdns', [
            'enabled'          => 'bool',
            'path'             => 'executable-path',
            'ttl'              => 'positive-int',
            'delay_hours'      => 'positive-int',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'domain_sync' => ['domain_sync', [
            'enabled'          => 'bool',
            'batch_size'       => 'range:1,500',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'domain_reap_deletions' => ['domain_reap_deletions', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        // 'enabled' defaults true (see the schema seed): disabling it stops
        // the registry password from auto-rotating on a passwdReminder, a
        // real foot-gun, but the operator's call to make -- see
        // PollProcessCommand and CronjobSettingsDialog's warning
        'poll_process' => ['poll_process', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'keepalive' => [null, [
            'enabled' => 'bool',
        ]],
    ];

    /** @return string[] every job name, for CLI usage text and GET /v1/cronjobs */
    public static function jobs(): array {
        return array_keys(self::JOBS);
    }

    /** @return string[] the fields $job accepts, in declared order */
    public static function fields(string $job): array {
        return array_keys(self::job($job)[1]);
    }

    /**
     * @return array the job's current settings -- the bare bool for
     *         `keepalive`, wrapped as `['enabled' => bool]` for a uniform
     *         shape callers don't have to special-case
     */
    public static function get(string $job): array {
        [$key] = self::job($job);
        if ($key === null) {
            return ['enabled' => (bool) Config::get('keepalive')];
        }
        return Config::get($key);
    }

    /**
     * Validate and coerce $changes, and compute what the job's full settings
     * would be afterward -- without writing anything. What a caller uses to
     * show "already set to X" / a confirm prompt with the real, coerced
     * value before deciding whether to actually call set().
     *
     * @param array $changes field => new value, or null to unset it
     * @return array{0: array, 1: array} [validated changes, resulting full settings]
     */
    public static function preview(string $job, array $changes, bool $force = false): array {
        [$key, $fields] = self::job($job);

        $unknown = array_diff(array_keys($changes), array_keys($fields));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "unknown field(s) for '{$job}': " . implode(', ', $unknown) .
                ' -- valid fields: ' . implode(', ', array_keys($fields))
            );
        }

        $validated = [];
        foreach ($changes as $field => $value) {
            $validated[$field] = $value === null ? null : self::validate($fields[$field], $field, $value, $force);
        }

        if ($key === null) {
            // keepalive: the only field is 'enabled', and it *is* the setting
            return [$validated, ['enabled' => $validated['enabled'] ?? (bool) Config::get('keepalive')]];
        }
        return [$validated, $validated + Config::get($key)];
    }

    /**
     * preview(), then persist and record the change to `history`. Throws
     * \InvalidArgumentException (message safe to show the caller) on an
     * unknown job/field or a failed validator -- callers translate that
     * into a UsageError (CLI) or a 400 (API).
     *
     * @param array $changes field => new value, or null to unset it. Only
     *              the given fields change; everything else is untouched.
     * @param bool $force skip the `path` field's is_executable() check
     * @return array the job's full settings after the change
     */
    public static function set(string $job, array $changes, int $userId, bool $force = false): array {
        [$key] = self::job($job);
        [$validated, $result] = self::preview($job, $changes, $force);

        Config::set($key ?? 'keepalive', $key === null ? $result['enabled'] : $result);

        History::record('cronjobs', 0, 'update', ['job' => $job, 'changes' => $validated], $userId);

        return $result;
    }

    /** Bookkeeping only, written by `cron run` -- never confirmed, never audited. */
    public static function markRun(string $job): void {
        [$key] = self::job($job);
        if ($key === null) {
            return; // keepalive tracks its own timing (SessionState)
        }
        $current = Config::get($key);
        $current['last_run_at'] = date('Y-m-d H:i:s');
        Config::set($key, $current);
    }

    /**
     * @return array{0: string|null, 1: array<string, string>}
     */
    private static function job(string $job): array {
        if ( ! array_key_exists($job, self::JOBS)) {
            throw new \InvalidArgumentException(
                "unknown job '{$job}' -- valid jobs: " . implode(', ', self::jobs())
            );
        }
        return self::JOBS[$job];
    }

    private static function validate(string $validator, string $field, mixed $value, bool $force): mixed {
        if ($validator === 'bool') {
            return self::parseBool($field, $value);
        }
        if ($validator === 'positive-int') {
            return self::parseInt($field, $value, 1, PHP_INT_MAX);
        }
        if (str_starts_with($validator, 'range:')) {
            [$min, $max] = array_map('intval', explode(',', substr($validator, 6)));
            return self::parseInt($field, $value, $min, $max);
        }
        if ($validator === 'executable-path') {
            $path = (string) $value;
            if ( ! $force && ! is_executable($path)) {
                throw new \InvalidArgumentException("'{$path}' is not executable -- pass --force/force to set it anyway");
            }
            return $path;
        }
        throw new \LogicException("no validator implemented for '{$validator}'"); // unreachable, guards a typo in JOBS
    }

    private static function parseBool(string $field, mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower((string) $value);
        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }
        throw new \InvalidArgumentException("{$field} must be true or false");
    }

    private static function parseInt(string $field, mixed $value, int $min, int $max): int {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            $int = (int) $value;
        } else {
            throw new \InvalidArgumentException("{$field} must be a whole number");
        }
        if ($int < $min || $int > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }
        return $int;
    }
}
