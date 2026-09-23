<?php

namespace Eppitnic\Cli\Command;

/**
 * Set or unset one `domain_sync` field: enabled, batch_size, or
 * frequency_minutes -- `config domain-sync <on|off>` is a friendlier shape
 * over the same `enabled` field. See AbstractCronjobSetCommand and
 * CronjobSettings.
 */
final class ConfigDomainSyncSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'domain_sync';
    }

    public function describe(): string {
        return 'set or unset a domain_sync.* setting: enabled, batch_size, frequency_minutes';
    }
}
