<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Move domains to another local user. Not just a `domains`.`user_id` update:
 * the registrant and admin contacts are copied under the new owner and the
 * domain repointed, or the two ownerships drift. Shared with the owner route.
 */
final class DomainSetOwnerCommand extends Command
{
    public function describe(): string {
        return 'move domains to another local user';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'new-owner=' => 'the local user id to move the domains to (required)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        // deliberately not --user, which every command uses for "act as"
        if ( ! $this->hasOption('new-owner')) {
            throw new UsageError('--new-owner=ID is required (the user to move the domains to)');
        }
        $newOwnerId = (int) $this->option('new-owner');
        if ($newOwnerId <= 0) {
            throw new UsageError('--new-owner must be a local user id');
        }

        // What this sends is built from records only the registry has, so a
        // preview would show the right shape with invented contents. Checked
        // before the prompt, so nobody confirms something that will not run
        if ($this->isDryRun()) {
            throw new UsageError(
                '--dry-run does not apply to set-owner: the contacts it creates are copies of'
                . ' registry records, which a dry run cannot read'
            );
        }

        if ( ! $this->confirm(
            'Move ' . count($names) . " domain(s) to user {$newOwnerId}?"
            . ' Their registrant and admin contacts will be duplicated at the registry.'
        )) {
            $this->line('nothing done');
            return 0;
        }

        $this->withSession(function ($nic) use ($names, $newOwnerId) {
            foreach ($names as $name) {
                $result = DomainService::changeOwner($nic, $name, $newOwnerId);

                if ( ! $result['ok']) {
                    $this->itemFailed($name, $result['error']);
                    continue;
                }

                $domain = $result['domain'];
                $this->record(
                    sprintf('%-40s moved to user %d (registrant %s)', $name, $newOwnerId, $domain->get('registrant')),
                    [
                        'domain'     => $name,
                        'user_id'    => $newOwnerId,
                        'registrant' => $domain->get('registrant'),
                        'admin'      => $domain->get('admin'),
                    ]
                );
            }
        });

        return $this->outcome(DOMAIN_UPDATE_FAILED);
    }
}
