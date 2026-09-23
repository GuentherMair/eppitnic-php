<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Domain;
use RedBeanPHP\R;

/**
 * Carry out domain deletions scheduled for today or earlier via
 * `DELETE /v1/domains/{name}?mode=expiry|date` -- that write only queues a
 * `tasks` row (object='registry', action='delete'); this is what actually
 * reaches the registry once the date arrives. The query is the gate: this
 * command must never act on a `registry` row that isn't explicitly a
 * delete, so `action = 'delete'` is part of the SQL itself, not a PHP-level
 * check that a future row shape could quietly bypass.
 *
 *   0-59/15 * * * *  /path/to/bin/eppitnic domain reap-deletions >> /var/log/eppitnic/domain-reap-deletions.log 2>&1
 */
final class DomainReapDeletionsCommand extends Command
{
    public function describe(): string {
        return 'delete domains whose scheduled deletion is now due';
    }

    public function options(): array {
        return [
            'dry-run' => 'show what would be deleted without deleting anything',
        ];
    }

    public function run(): int {
        $this->database();

        $rows = R::getAll("SELECT * FROM tasks WHERE object = 'registry' AND action = 'delete' AND active = 1 AND date <= CURRENT_DATE ORDER BY id ASC");
        if ($rows === []) {
            $this->line('no deletions due');
            return 0;
        }

        $userId = $this->userId();

        $this->withSession(function ($nic) use ($rows, $userId) {
            foreach ($rows as $row) {
                $domain = new Domain($nic);
                $ok = $domain->delete($row['domain']);
                $message = $ok ? 'domain deleted' : ($domain->getError() ?: 'registry refused the delete');

                // a dry run reached a synthetic success, so neither the local
                // row nor the task itself must be touched
                if ($ok && ! $this->isDryRun()) {
                    $domain->deleteDomainDB($row['domain'], $userId, true);
                }

                $this->record(
                    sprintf('[%s] %-40s %s', $row['id'], $row['domain'], $message),
                    ['id' => (int) $row['id'], 'domain' => $row['domain'], 'status' => $ok ? 'applied' : 'failed', 'message' => $message]
                );

                if ( ! $ok) {
                    $this->itemFailed($row['domain'], $message);
                }
                $this->markExecuted((int) $row['id'], $ok, $message);
            }
        });

        return $this->outcome(DOMAIN_DELETE_FAILED);
    }

    /**
     * Only a success is terminal -- see PdnsSyncCommand's own note. A failed
     * attempt still records what happened, but stays active so the next run
     * retries it.
     */
    private function markExecuted(int $id, bool $success, string $message): void {
        if ($this->isDryRun()) {
            return;
        }
        R::exec(
            "UPDATE tasks SET executed_time = CURRENT_TIMESTAMP, exit_code = ?, exit_message = ?"
            . ($success ? ", active = 0" : "") . " WHERE id = ?",
            [$success ? 0 : 1, $message, $id]
        );
    }
}
