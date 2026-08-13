<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;

/**
 * Change a domain's registrant.
 *
 * Its own verb rather than an option on `domain update`, because it is its own
 * EPP command: the registry requires the authinfo to change alongside the
 * registrant, and ignores nameserver or technical-contact changes sent with
 * it.
 */
final class DomainSetRegistrantCommand extends Command
{
    public function describe(): string {
        return 'change the registrant of domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='       => 'read domain names from this file, one per line',
            'registrant=' => 'the new registrant contact handle (required)',
            'authinfo='   => 'the new authinfo code (generated when omitted)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }
        if ( ! $this->hasOption('registrant')) {
            throw new UsageError('--registrant=HANDLE is required');
        }
        $registrant = (string) $this->option('registrant');

        if ( ! $this->confirm(
            'Change the registrant of ' . count($names) . " domain(s) to {$registrant}?"
            . ' Their authinfo codes will be rotated.'
        )) {
            $this->line('nothing done');
            return 0;
        }

        $failures = 0;

        $this->withSession(function ($nic) use ($names, $registrant, &$failures) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                if ( ! $domain->fetch($name)) {
                    $failures++;
                    $this->warn("{$name}: " . ($domain->getError() ?: 'not found'));
                    continue;
                }

                $domain->set('registrant', $registrant);
                // the registry rejects a registrant change that does not also
                // change the authinfo, so one is always set
                $domain->set('authinfo', (string) $this->option('authinfo', $domain->authinfo()));

                if ( ! $domain->updateRegistrant()) {
                    $failures++;
                    $this->warn("{$name}: " . $domain->getError());
                    continue;
                }

                if ( ! $this->isDryRun()) {
                    // updateDB() moves the domain to the new registrant's owner
                    $domain->updateDB($name, $this->userId(), true);
                }

                $this->record(
                    sprintf('%-40s registrant %s (authinfo %s)', $name, $registrant, $domain->get('authinfo')),
                    ['domain' => $name, 'registrant' => $registrant, 'authinfo' => $domain->get('authinfo')]
                );
            }
        });

        return $failures > 0 ? DOMAIN_UPDATE_FAILED : 0;
    }
}
