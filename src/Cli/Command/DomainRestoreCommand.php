<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Domain;

/**
 * Recover domains from the registry's redemption period.
 */
final class DomainRestoreCommand extends Command
{
    public function describe(): string {
        return 'restore domains from the redemption period';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'all-users' => 'operate on any user\'s domains, not just --user\'s',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ( ! $this->confirm('Restore ' . count($names) . ' domain(s)? This is a chargeable operation.')) {
            $this->line('nothing done');
            return 0;
        }

        $epp = Config::get('epp');
        $endpoint = $epp['server_deleted'] ?? '';
        if ($endpoint === '') {
            $this->warn("the 'epp' setting has no server_deleted endpoint configured");
            return CONFIG_ERROR;
        }

        $userId = $this->userId();
        $allUsers = $this->hasOption('all-users');

        $this->line("using {$endpoint}");

        // a client of its own: restores are served from a different host than
        // every other command, so this session cannot be the ordinary one
        $this->withSession(function ($nic) use ($names, $userId, $allUsers) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                if ( ! $domain->restore($name)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                if ( ! $this->isDryRun()) {
                    $domain->restoreDomainDB($name, $userId, $allUsers);
                }

                $this->record("{$name} restored", ['domain' => $name, 'restored' => true]);
            }
        }, new Client($endpoint));

        return $this->outcome(DOMAIN_RESTORE_FAILED);
    }
}
