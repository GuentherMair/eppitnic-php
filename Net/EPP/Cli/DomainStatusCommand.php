<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Domain;

/**
 * Add or remove a client-side status on domains.
 */
final class DomainStatusCommand extends Command
{
    private const STATES = [
        'clientDeleteProhibited',
        'clientUpdateProhibited',
        'clientTransferProhibited',
        'clientHold',
        'clientLock',
    ];

    public function describe(): string {
        return 'add or remove a client status on domains';
    }

    public function arguments(): string {
        return 'add|rem <state> <domain>...';
    }

    public function options(): array {
        return [
            'file=' => 'read domain names from this file, one per line',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();

        // the first two positional arguments are the operation and the state;
        // names() has already folded --file in, so take them off the front
        $action = array_shift($names);
        $state = array_shift($names);

        if ( ! in_array($action, ['add', 'rem'], true)) {
            throw new UsageError("first argument must be 'add' or 'rem'");
        }
        if ( ! in_array($state, self::STATES, true)) {
            throw new UsageError('state must be one of: ' . implode(', ', self::STATES));
        }
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $verb = $action === 'add' ? 'Set' : 'Clear';
        if ( ! $this->confirm("{$verb} {$state} on " . count($names) . ' domain(s)?')) {
            $this->line('nothing done');
            return 0;
        }

        $failures = 0;

        $this->withSession(function ($nic) use ($names, $action, $state, &$failures) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                // updateStatus() works off the object's own status list, so the
                // domain has to be read before it can be changed
                if ( ! $domain->fetch($name)) {
                    $failures++;
                    $this->warn("{$name}: " . ($domain->getError() ?: 'not found'));
                    continue;
                }

                if ( ! $domain->updateStatus($state, $action)) {
                    $failures++;
                    $this->warn("{$name}: " . $domain->getError());
                    continue;
                }

                $this->record(
                    "{$name} " . ($action === 'add' ? 'now has ' : 'no longer has ') . $state,
                    ['domain' => $name, 'state' => $state, 'action' => $action]
                );
            }
        });

        return $failures > 0 ? DOMAIN_UPDATE_FAILED : 0;
    }
}
