<?php

namespace Eppitnic\Cli\Command;

/**
 * Set or unset one `pdns` field: enabled, ttl, delay_hours, or
 * frequency_minutes. See `config pdns-api`/`config pdns-nameserver` for
 * the `apis`/`nameservers` lists, and AbstractCronjobSetCommand for the rest.
 */
final class ConfigPdnsSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'pdns';
    }

    public function describe(): string {
        return 'set or unset a pdns.* setting: enabled, ttl, delay_hours, frequency_minutes';
    }
}
