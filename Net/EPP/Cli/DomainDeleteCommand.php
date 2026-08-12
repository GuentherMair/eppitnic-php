<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Domain;

/**
 * Delete domains at the registry, and deactivate them locally.
 *
 * Replaces CLI/domain-DoDelete.php and examples/013.
 */
final class DomainDeleteCommand extends Command
{
    public function describe(): string {
        return 'delete domains at the registry';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='     => 'read domain names from this file, one per line',
            'all-users' => 'operate on any user\'s domains, not just --user\'s',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ( ! $this->confirm('Delete ' . count($names) . ' domain(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $userId = $this->userId();
        $isAdmin = $this->hasOption('all-users');
        $failures = 0;

        $this->withSession(function ($nic) use ($names, $userId, $isAdmin, &$failures) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                if ( ! $domain->delete($name)) {
                    $failures++;
                    $this->warn("{$name}: " . $domain->getError());
                    continue;
                }

                // a dry run reached this point on a synthetic success, so the
                // local row must not be touched
                if ( ! $this->isDryRun()) {
                    $domain->deleteDomainDB($name, $userId, $isAdmin);
                }

                $this->record("{$name} deleted", ['domain' => $name, 'deleted' => true]);
            }
        });

        return $failures > 0 ? DOMAIN_DELETE_FAILED : 0;
    }
}
