<?php

namespace Net\EPP\Cli;

use Net\EPP\Service\DomainService;

/**
 * Copy domains the registry already holds into the local database.
 *
 * Reconciliation, not registration: nothing is created at the registry, and a
 * name the registry does not have is deactivated locally. Shares its steps
 * with POST /v1/domains/import through DomainService.
 *
 * Replaces CLI/domain-DoImport.php.
 */
final class DomainImportCommand extends Command
{
    public function describe(): string {
        return 'import registry domains into the local database';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file=' => 'read domain names from this file, one per line',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ($this->isDryRun()) {
            // import's writes are all local, so there is no request to preview
            // and nothing that --dry-run could usefully show
            throw new UsageError('--dry-run does not apply to import: it changes nothing at the registry');
        }

        $userId = $this->userId();

        $results = $this->withSession(
            fn($nic) => DomainService::import($nic, $names, $userId)
        );

        $failures = 0;
        foreach ($results as $name => $steps) {
            $ok = $steps['step4_dom_store'] === 'stored';
            if ( ! $ok) {
                $failures++;
            }

            $this->record(
                sprintf('%-40s domain %s, registrant %s, stored %s/%s',
                    $name,
                    $steps['step1_domain'],
                    $steps['step2_registrant'],
                    $steps['step3_reg_store'],
                    $steps['step4_dom_store']),
                ['domain' => $name] + $steps
            );
        }

        return $failures > 0 ? DOMAIN_IMPORT_FAILED : 0;
    }
}
