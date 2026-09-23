<?php

namespace Eppitnic\Cli\Command;

/**
 * Set or unset one `domain_reap_deletions` field: enabled, or
 * frequency_minutes. See AbstractCronjobSetCommand and CronjobSettings.
 */
final class ConfigDomainReapSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'domain_reap_deletions';
    }

    public function describe(): string {
        return 'set or unset a domain_reap_deletions.* setting: enabled, frequency_minutes';
    }
}
