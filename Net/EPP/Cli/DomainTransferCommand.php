<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Domain;
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

        $userId = $this->userId();
        $dryRun = $this->isDryRun();
        $failures = 0;

        $this->withSession(function ($nic) use ($targets, $operation, $userId, $dryRun, &$failures) {
            foreach ($targets as $name => $authinfo) {
                $domain = new Domain($nic);

                $ok = match ($operation) {
                    'request' => $domain->transfer($name, $authinfo),
                    'approve' => $domain->transferApprove($name, $authinfo),
                    'reject'  => $domain->transferReject($name, $authinfo),
                    'cancel'  => $domain->transferCancel($name, $authinfo),
                };

                if ( ! $ok) {
                    $failures++;
                    $this->warn("{$name}: " . $domain->getError());
                    continue;
                }

                if ( ! $dryRun) {
                    $this->recordLocally($operation, $name, $authinfo, $userId);
                }

                $this->record("{$name} transfer {$operation} accepted",
                    ['domain' => $name, 'operation' => $operation]);
            }
        });

        return $failures > 0 ? DOMAIN_TRANSFER_FAILED : 0;
    }

    /**
     * Keep the local `transfers` table in step with what was just asked for.
     *
     * A request creates the pending row PollProcessor later acts on; approve
     * and reject settle somebody else's claim and clear it. Cancel
     * deliberately does not: it withdraws an outgoing request of our own, and
     * the row is what records that we wanted the domain -- matching what
     * POST /v1/domains/{name}/transfer/cancel does.
     */
    private function recordLocally(string $operation, string $name, string $authinfo, int $userId): void {
        if ($operation === 'request') {
            R::exec("
                INSERT INTO transfers (user_id, domain, registrant, techc, dns)
                VALUES (:user_id, :domain, '', :techc, :dns)
            ", [
                ':user_id' => $userId,
                ':domain'  => $name,
                ':techc'   => serialize([]),
                ':dns'     => serialize([]),
            ]);
            return;
        }

        if ($operation === 'approve' || $operation === 'reject') {
            R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
        }
    }
}
