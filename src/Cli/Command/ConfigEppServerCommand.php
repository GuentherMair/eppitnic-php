<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\EppSettings;

/**
 * Show, set, or toggle which registry `epp.server` and `epp.server_deleted`
 * point at -- a local write only, leaving username/password/cl_trid_prefix
 * alone: nic.it issues separate credentials per registry.
 */
final class ConfigEppServerCommand extends Command
{
    private const ENDPOINTS = [
        'production' => 'https://epp.nic.it',
        'test'       => 'https://epp.pubtest.nic.it',
    ];

    /** the `-deleted` endpoint `domain restore` talks to, per registry */
    private const DELETED_ENDPOINTS = [
        'production' => 'https://epp-deleted.nic.it',
        'test'       => 'https://epp-deleted.pubtest.nic.it',
    ];

    public function describe(): string {
        return "show, set, or toggle epp.server ('production'/'test')";
    }

    public function arguments(): string {
        return '[production|test|toggle]';
    }

    public function options(): array {
        // not self::MUTATING_OPTIONS verbatim -- its --dry-run wording talks
        // about the EPP request that would be sent, and this command never
        // opens a registry session at all, only writes a local setting
        return self::LOCAL_MUTATING_OPTIONS;
    }

    /**
     * @return string 'production', 'test', or 'custom' for anything else
     *                (a hand-edited URL, or unset)
     */
    private static function which(string $server): string {
        return array_flip(self::ENDPOINTS)[$server] ?? 'custom';
    }

    public function run(): int {
        $this->database();

        $epp = Config::get('epp');
        $current = (string) ($epp['server'] ?? '');
        $target = $this->arguments[0] ?? null;

        if ($target === null) {
            $label = $current !== '' ? self::which($current) : 'unset';
            $this->record($current !== '' ? "{$current} ({$label})" : '(not set)', [
                'server' => $current,
                'which'  => $label,
            ]);
            return 0;
        }

        if ($target === 'toggle') {
            $label = self::which($current);
            if ($label === 'custom') {
                throw new UsageError(
                    "current epp.server ('{$current}') is neither production nor test -- " .
                    "give 'production' or 'test' explicitly"
                );
            }
            $target = $label === 'production' ? 'test' : 'production';
        }

        if ( ! array_key_exists($target, self::ENDPOINTS)) {
            throw new UsageError("argument must be 'production', 'test' or 'toggle'");
        }

        $new = self::ENDPOINTS[$target];
        $newDeleted = self::DELETED_ENDPOINTS[$target];

        if ($new === $current && $newDeleted === ($epp['server_deleted'] ?? null)) {
            $this->line("epp.server is already {$new} ({$target})");
            return 0;
        }

        if ($target === 'production') {
            $this->warn(
                'This points every subsequent registry command at the LIVE production ' .
                'registry (epp.nic.it) -- real domains, real billing.'
            );
        }
        if ( ! $this->confirm("Set epp.server to {$new} ({$target})?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set epp.server: '{$current}' -> '{$new}', epp.server_deleted -> '{$newDeleted}'");
            return 0;
        }

        EppSettings::set(['server' => $new, 'server_deleted' => $newDeleted], $this->userId());

        $this->warn(
            "username/password/cl_trid_prefix were left untouched -- production and the " .
            "public test registry normally use separate accounts. Verify those match " .
            "{$target} before running anything against it."
        );
        $this->record(
            "epp.server set to {$new}, epp.server_deleted to {$newDeleted} ({$target})",
            ['server' => $new, 'server_deleted' => $newDeleted, 'which' => $target]
        );
        return 0;
    }
}
