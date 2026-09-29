<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DnssecSettings;

/**
 * Turn DNSSEC on or off -- see docs/INSTALL.md's "DNSSEC". Off, DS records
 * are refused on domain create/update and login does not announce secDNS.
 */
final class ConfigDnssecCommand extends Command
{
    public function describe(): string {
        return 'turn DNSSEC (DS records on domains) on or off';
    }

    public function arguments(): string {
        return '<on|off>';
    }

    public function options(): array {
        // only a local setting is written, never a registry session opened
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $value = $this->arguments[0] ?? null;
        if ( ! in_array($value, ['on', 'off'], true)) {
            throw new UsageError("give 'on' or 'off'");
        }
        $desired = $value === 'on';

        if (DnssecSettings::active() === $desired) {
            $this->line("dnssec is already {$value}");
            return 0;
        }

        if ( ! $this->confirm("Turn dnssec {$value}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would turn dnssec {$value}");
            return 0;
        }

        DnssecSettings::set($desired, $this->userId());

        $this->record("dnssec turned {$value}", ['dnssec' => $desired]);
        return 0;
    }
}
