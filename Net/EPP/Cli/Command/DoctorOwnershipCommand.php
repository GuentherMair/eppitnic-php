<?php

namespace Net\EPP\Cli\Command;

use Net\EPP\Cli\Command;
use RedBeanPHP\R;

/**
 * Report rows where the two notions of local ownership disagree.
 *
 * There are two: `domains`.`user_id` / `transfers`.`user_id` (who owns the
 * domain, or requested the transfer-in) and `contacts`.`user_id` (who owns the
 * contact acting as its registrant). From 7.0.0 on they are expected to agree,
 * because every domain route scopes non-admins by the domain's own owner while
 * a registrant change reassigns the domain to the contact's owner -- so a
 * disagreement means a domain that answers to one user in the listings and
 * another in the renewals view.
 */
final class DoctorOwnershipCommand extends Command
{
    public function describe(): string {
        return 'report domains whose owner and registrant owner disagree';
    }

    public function options(): array {
        return [
            'quiet' => 'report the rows only, without the explanation of how to resolve them',
        ];
    }

    public function run(): int {
        $this->database();

        $domains = $this->mismatches('domains', 'd');
        $transfers = $this->mismatches('transfers', 't');

        foreach (['domain' => $domains, 'pending transfer-in' => $transfers] as $label => $rows) {
            foreach ($rows as $row) {
                $this->record(
                    sprintf('%-18s %-32s owned by %s (%d), registrant %s owned by %s (%d)',
                        $label, $row['object'],
                        $row['owner_name'], $row['owner_id'],
                        $row['registrant'],
                        $row['registrant_owner_name'], $row['registrant_owner_id']),
                    ['kind' => $label] + $row
                );
            }
        }

        if ($domains === [] && $transfers === []) {
            $this->line('domain/registrant ownership is coherent');
            return 0;
        }

        if ( ! $this->hasOption('quiet')) {
            $this->line('');
            $this->line('Per row, decide who should own it, then either:');
            $this->line('  (a) give the owner their own copy of the registrant contact --');
            $this->line('      POST /v1/domains/{name}/owner does exactly this; or');
            $this->line('  (b) hand the domain to the registrant\'s owner --');
            $this->line('      UPDATE domains SET user_id = <registrant_owner> WHERE domain = \'<domain>\';');
            $this->line('');
            $this->line('(b) is one statement but moves the domain out of its current owner\'s');
            $this->line('listings. A pending transfer-in has not gone wrong yet: it will create a');
            $this->line('mismatched row when it completes, so fix its registrant before then.');
        }

        return DATA_INCONSISTENT;
    }

    /**
     * @param string $table domains or transfers
     * @param string $alias its alias in the query
     * @return array<int, array<string, mixed>>
     */
    private function mismatches(string $table, string $alias): array {
        return R::getAll("
            SELECT
              {$alias}.domain   AS object,
              {$alias}.user_id  AS owner_id,
              owner.username    AS owner_name,
              {$alias}.registrant AS registrant,
              c.user_id         AS registrant_owner_id,
              reg.username      AS registrant_owner_name
            FROM {$table} {$alias}
            JOIN contacts c    ON c.handle = {$alias}.registrant
            JOIN users owner   ON owner.id = {$alias}.user_id
            JOIN users reg     ON reg.id   = c.user_id
            WHERE {$alias}.user_id <> c.user_id
            ORDER BY {$alias}.domain
        ");
    }
}
