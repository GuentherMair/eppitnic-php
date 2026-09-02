<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Show, set, or toggle which EPP endpoint `epp.server` points at.
 *
 * A local settings write only -- no registry session opens, and
 * username/password/cl_trid_prefix are left untouched. nic.it issues
 * separate credentials for production and for the public test registry, so
 * pointing `server` at one does not make the other's account work against
 * it; update those too (setup, or by hand -- see INSTALL.md's
 * "Configuration") when the account changes, not just the host.
 */
final class ConfigEppServerCommand extends Command
{
    private const ENDPOINTS = [
        'production' => 'https://epp.nic.it',
        'test'       => 'https://epp.pubtest.nic.it',
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
        return [
            'dry-run' => 'print what would change, without writing it',
            'yes'     => 'do not ask for confirmation',
        ];
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

        if ($new === $current) {
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
            $this->line("would set epp.server: '{$current}' -> '{$new}'");
            return 0;
        }

        $epp['server'] = $new;
        Config::set('epp', $epp);

        $this->warn(
            "username/password/cl_trid_prefix were left untouched -- production and the " .
            "public test registry normally use separate accounts. Verify those match " .
            "{$target} before running anything against it."
        );
        $this->record("epp.server set to {$new} ({$target})", ['server' => $new, 'which' => $target]);
        return 0;
    }
}
