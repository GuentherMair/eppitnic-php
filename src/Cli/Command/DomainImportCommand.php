<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Copy domains the registry already holds into the local database.
 *
 * Reconciliation, not registration: nothing is created at the registry, and a
 * name the registry does not have is deactivated locally. Shares its steps
 * with POST /v1/domains/import through DomainService.
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
            $ok = $steps['domain_stored'] === 'stored';
            if ( ! $ok) {
                $failures++;
            }

            $this->record(
                sprintf('%-40s domain %s, registrant %s, contact %s, domain %s',
                    $name,
                    $steps['domain'],
                    $steps['registrant'],
                    $steps['contact_stored'],
                    $steps['domain_stored']),
                // 'name' rather than 'domain': within a result, 'domain' is
                // the outcome of the domain lookup, not which domain it was
                ['name' => $name] + $steps
            );
        }

        return $failures > 0 ? DOMAIN_IMPORT_FAILED : 0;
    }
}
