<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\CronjobSettings;

/**
 * Turn periodic domain/contact reconciliation against the registry on or off
 * -- see docs/INSTALL.md's "Domain reconciliation" for what `domain sync`
 * does with the setting. A friendlier <on|off> shape over the same field
 * `config domain-sync-set enabled <true|false>` also reaches; both go
 * through CronjobSettings, so either is audited the same way.
 */
final class ConfigDomainSyncCommand extends Command
{
    public function describe(): string {
        return 'turn periodic domain/contact reconciliation against the registry on or off';
    }

    public function arguments(): string {
        return '<on|off>';
    }

    public function options(): array {
        // not MUTATING_OPTIONS: this only ever writes a local setting, never
        // opens a registry session for its own sake -- same reasoning as
        // ConfigKeepaliveCommand
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $value = $this->arguments[0] ?? null;
        if ( ! in_array($value, ['on', 'off'], true)) {
            throw new UsageError("give 'on' or 'off'");
        }
        $desired = $value === 'on';

        $current = (bool) CronjobSettings::get('domain_sync')['enabled'];
        if ($current === $desired) {
            $this->line("domain sync is already {$value}");
            return 0;
        }

        if ( ! $this->confirm("Turn domain sync {$value}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would turn domain sync {$value}");
            return 0;
        }

        CronjobSettings::set('domain_sync', ['enabled' => $desired], $this->userId());

        $this->record("domain sync turned {$value}", ['enabled' => $desired]);
        return 0;
    }
}
