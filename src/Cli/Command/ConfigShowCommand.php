<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Show the current `settings` table -- all there is to inspect, once
 * config/config.php holds nothing but the database credentials. A local,
 * read-only dump: no registry session opens.
 */
final class ConfigShowCommand extends Command
{
    public function describe(): string {
        return 'show the current settings (config/config.php holds only the database credentials)';
    }

    public function arguments(): string {
        return '[<key>]';
    }

    public function run(): int {
        $this->database();

        $settings = Config::all();
        $key = $this->arguments[0] ?? null;

        if ($key !== null) {
            if ( ! array_key_exists($key, $settings)) {
                throw new UsageError("no such setting '{$key}' -- run 'config show' with no argument to list them");
            }
            $settings = [$key => $settings[$key]];
        }

        ksort($settings);
        foreach ($settings as $name => $value) {
            $value = $this->redact($name, $value);
            $this->record("{$name}: " . json_encode($value, JSON_UNESCAPED_SLASHES), ['key' => $name, 'value' => $value]);
        }

        return 0;
    }

    /**
     * Withhold the two secrets this table holds. Everything else here is
     * plain configuration (paths, toggles, limits) with nothing to protect.
     */
    private function redact(string $key, mixed $value): mixed {
        if ($key === 'jwt_psk') {
            return '[redacted]';
        }

        if ($key === 'epp' && is_array($value)) {
            // the same allow-list GET /v1/session/epp answers from
            $public = array_intersect_key($value, array_flip(Config::EPP_PUBLIC_FIELDS));
            // same reasoning as GET /v1/session/epp: report whether a
            // credential/rotation is present, never the credential itself
            $public['password_set'] = ($value['password'] ?? '') !== '';
            $public['rotation_pending'] = ($value['pendingPassword'] ?? '') !== '';
            return $public;
        }

        return $value;
    }
}
