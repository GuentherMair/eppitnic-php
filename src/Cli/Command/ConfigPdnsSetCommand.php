<?php

namespace Eppitnic\Cli\Command;

/**
 * Set or unset one `pdns` field: enabled, path, ttl, delay_hours, or
 * frequency_minutes. See AbstractCronjobSetCommand and CronjobSettings.
 */
final class ConfigPdnsSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'pdns';
    }

    public function describe(): string {
        return 'set or unset a pdns.* setting: enabled, path, ttl, delay_hours, frequency_minutes';
    }
}
