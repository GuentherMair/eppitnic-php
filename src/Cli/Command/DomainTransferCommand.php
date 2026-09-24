<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use RedBeanPHP\R;

/**
 * The four sides of a domain transfer.
 *
 *   request  claim a domain held by another registrar
 *   approve  agree to somebody else's claim on a domain we hold
 *   reject   refuse it
 *   cancel   withdraw a request we made ourselves
 */
final class DomainTransferCommand extends Command
{
    private const OPERATIONS = ['request', 'approve', 'reject', 'cancel'];

    public function describe(): string {
        return 'request, approve, reject or cancel a domain transfer';
    }

    public function arguments(): string {
        return 'request|approve|reject|cancel <domain>...';
    }

    public function options(): array {
        return [
            'file='     => "read rows from this file: 'domain' or 'domain;authinfo' per line",
            'authinfo=' => 'the authinfo code, when it is the same for every domain',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        $operation = array_shift($names);

        if ( ! in_array($operation, self::OPERATIONS, true)) {
            throw new UsageError('first argument must be one of: ' . implode(', ', self::OPERATIONS));
        }
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        // 'domain;authinfo' rows, or a shared --authinfo
        $shared = (string) $this->option('authinfo', '');
        $targets = [];
        foreach ($names as $line) {
            $fields = array_map('trim', explode(';', $line));
            $targets[$fields[0]] = $fields[1] ?? $shared;
        }

        // a request is the one operation the registry will not accept without
        // it; the others authorise against a domain we already sponsor
        if ($operation === 'request') {
            foreach ($targets as $name => $authinfo) {
                if ($authinfo === '') {
                    throw new UsageError("no authinfo for '{$name}': pass --authinfo, or use 'domain;authinfo' rows");
                }
            }
        }

        if ( ! $this->confirm(ucfirst($operation) . ' transfer of ' . count($targets) . ' domain(s)?')) {
            $this->line('nothing done');
            return 0;
        }

        $dryRun = $this->isDryRun();
        // a dry run records nothing locally, so it needs no database either
        $scope = $dryRun ? Scope::operator($this->userId()) : $this->scope();

        $this->withSession(function ($nic) use ($targets, $operation, $scope, $dryRun) {
            foreach ($targets as $name => $authinfo) {
                $domain = new Domain($nic);

                $ok = match ($operation) {
                    'request' => $domain->transfer($name, $authinfo),
                    'approve' => $domain->transferApprove($name, $authinfo),
                    'reject'  => $domain->transferReject($name, $authinfo),
                    'cancel'  => $domain->transferCancel($name, $authinfo),
                };

                if ( ! $ok) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                if ( ! $dryRun) {
                    $this->recordLocally($operation, $name, $authinfo, $scope);
                }

                $this->record("{$name} transfer {$operation} accepted",
                    ['domain' => $name, 'operation' => $operation]);
            }
        });

        return $this->outcome(DOMAIN_TRANSFER_FAILED);
    }

    /**
     * Keep `transfers` in step: a request creates the pending row PollProcessor
     * acts on, approve and reject settle someone else's claim and clear it.
     * Cancel does not -- the row records that we wanted the domain.
     */
    private function recordLocally(string $operation, string $name, string $authinfo, Scope $scope): void {
        if ($operation === 'request') {
            R::exec("
                INSERT INTO transfers (reseller_id, domain, registrant, techc, dns)
                VALUES (:reseller_id, :domain, '', :techc, :dns)
            ", [
                ':reseller_id' => $scope->resellerId,
                ':domain'      => $name,
                ':techc'       => serialize([]),
                ':dns'         => serialize([]),
            ]);
            // what the reseller's daily quota counts, as the API writes it
            History::record('domains', 0, 'request', ['domain' => $name, 'kind' => 'transfer'], $scope->userId);
            return;
        }

        if ($operation === 'approve' || $operation === 'reject') {
            R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
        }
    }
}
