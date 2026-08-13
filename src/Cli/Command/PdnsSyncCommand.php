<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Config;
use RedBeanPHP\R;

/**
 * Apply pending DNS-sync events to a PowerDNS authoritative server.
 *
 * `reminder` rows with an `action` are written by the domain routes and by
 * PollProcessor as domains are created, changed and removed. This is the half
 * that acts on them, and it is the one part of the codebase that knows what
 * product is serving the zones -- hence its own verb rather than a switch on
 * some general `dns sync`. An installation running BIND, or nothing, simply
 * never schedules it.
 *
 * PowerDNS is driven through `pdnsutil` invocations, never a direct connection
 * to its backend database, so this works whichever backend PowerDNS itself is
 * configured with. `pdnsutil` must be on the PATH, or named by the
 * `pdnsutil_path` setting, with permission to manage zones -- a deployment
 * concern this cannot arrange for itself.
 *
 * The subcommands used (create-zone, delete-zone, add-record, delete-rrset)
 * are long-standing and documented, but argument shapes have shifted between
 * PowerDNS versions; check them against the target server before relying on
 * this. `--dry-run` prints the exact invocations without running any.
 *
 * Suggested crontab entry, every fifteen minutes:
 *
 *   0-59/15 * * * *  /path/to/bin/eppitnic pdns sync >> /var/log/eppitnic/pdns-sync.log 2>&1
 *
 * create and update share one path: ensure the zone exists, then reconcile its
 * apex NS set to match `domains`.`ns`. That is idempotent and correct whether
 * the zone was there already or not, so the two need no separate handling.
 *
 * A delete waits until the row is `--delay-hours` old (12 by default), a grace
 * period before a zone is torn down. Rows not yet due are left for a later run.
 *
 * Each row that succeeds is archived (`active` = 0). A row that fails stays
 * active, so the next run retries it.
 */
final class PdnsSyncCommand extends Command
{
    public function describe(): string {
        return 'apply pending DNS-sync events to PowerDNS via pdnsutil';
    }

    public function options(): array {
        return [
            'delay-hours=' => 'hours to wait before applying a delete (default 12)',
            'dry-run'      => 'print the pdnsutil invocations without running any',
        ];
    }

    private string $pdnsutil = 'pdnsutil';
    private int $ttl = 3600;

    public function run(): int {
        $this->database();

        $delayHours = (int) $this->option('delay-hours', 12);
        $this->pdnsutil = (string) (Config::get('pdnsutil_path') ?: 'pdnsutil');
        $this->ttl = (int) (Config::get('pdnsutil_ttl') ?: 3600);

        $rows = R::getAll("SELECT * FROM reminder WHERE active = 1 AND action IS NOT NULL ORDER BY id ASC");
        if ($rows === []) {
            $this->line('no pending DNS-sync events');
            return 0;
        }

        $applied = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $outcome = $row['action'] === 'delete'
                ? $this->applyDelete($row, $delayHours)
                : $this->applyUpsert($row);

            $this->record(
                sprintf('[%s] %-40s %-8s %s', $row['id'], $row['domain'], $row['action'], $outcome['message']),
                [
                    'id'      => (int) $row['id'],
                    'domain'  => $row['domain'],
                    'action'  => $row['action'],
                    'status'  => $outcome['status'],
                    'message' => $outcome['message'],
                ]
            );

            if ($outcome['status'] === 'failed') {
                $failed++;
                continue;
            }
            if ($outcome['status'] === 'applied') {
                $applied++;
                // under --dry-run the pdnsutil calls never ran, so archiving
                // the row would lose the work it still stands for
                if ( ! $this->isDryRun()) {
                    R::exec("UPDATE reminder SET active = 0 WHERE id = ?", [$row['id']]);
                }
            }
        }

        $this->line('');
        $this->line(sprintf('%d event(s) %s, %d failed', $applied,
            $this->isDryRun() ? 'would be applied' : 'applied', $failed));

        return $failed > 0 ? DNS_SYNC_FAILED : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status: string, message: string}
     */
    private function applyDelete(array $row, int $delayHours): array {
        $age = $this->rowAgeInHours($row);

        if ($age === null) {
            // no usable timestamp, so the grace period cannot be judged.
            // Tearing down a zone whose age is unknown is the one mistake here
            // that cannot be walked back, so the row waits and says so.
            return ['status' => 'deferred', 'message' => 'no usable created_time, not applying a delete'];
        }
        if ($age < $delayHours) {
            return ['status' => 'deferred', 'message' => "not due yet (delay {$delayHours}h)"];
        }

        [$code, $output] = $this->pdnsutil('delete-zone ' . escapeshellarg((string) $row['domain']));
        if ($code !== 0) {
            return ['status' => 'failed', 'message' => 'FAILED: ' . implode(' ', $output)];
        }
        return ['status' => 'applied', 'message' => 'zone deleted'];
    }

    /**
     * create and update, which are the same operation -- see the class note.
     *
     * @param array<string, mixed> $row
     * @return array{status: string, message: string}
     */
    private function applyUpsert(array $row): array {
        $zone = (string) $row['domain'];

        $domainRow = R::getRow("SELECT ns FROM domains WHERE domain = ?", [$zone]);
        if (empty($domainRow)) {
            return ['status' => 'skipped', 'message' => 'SKIPPED: domain not found locally'];
        }

        $nsData = empty($domainRow['ns']) ? [] : unserialize($domainRow['ns']);
        $nameservers = array_keys((array) $nsData);
        if ($nameservers === []) {
            return ['status' => 'skipped', 'message' => 'SKIPPED: domain has no nameservers on record'];
        }

        if ( ! $this->zoneExists($zone)) {
            $nsArgs = implode(' ', array_map('escapeshellarg', $nameservers));
            [$code, $output] = $this->pdnsutil('create-zone ' . escapeshellarg($zone) . ' ' . $nsArgs);
            if ($code !== 0) {
                return ['status' => 'failed', 'message' => 'FAILED to create zone: ' . implode(' ', $output)];
            }
        }

        [$code, $output] = $this->pdnsutil(
            'delete-rrset ' . escapeshellarg($zone) . ' ' . escapeshellarg($zone) . ' NS'
        );
        if ($code !== 0) {
            return ['status' => 'failed', 'message' => 'FAILED to clear apex NS records: ' . implode(' ', $output)];
        }

        foreach ($nameservers as $ns) {
            $content = escapeshellarg(rtrim((string) $ns, '.') . '.');
            [$code, $output] = $this->pdnsutil(
                'add-record ' . escapeshellarg($zone) . ' ' . escapeshellarg($zone) . " NS {$this->ttl} {$content}"
            );
            if ($code !== 0) {
                return ['status' => 'failed', 'message' => "FAILED to add NS record {$ns}: " . implode(' ', $output)];
            }
        }

        return ['status' => 'applied', 'message' => 'zone synced (' . count($nameservers) . ' NS records)'];
    }

    /**
     * How long ago the row was written, by the database's own clock.
     *
     * Both times come from the database rather than one from PHP: `created_time`
     * is a CURRENT_TIMESTAMP default, so comparing it against PHP's clock would
     * make the grace period depend on the two agreeing about the timezone.
     * Their difference does not care.
     *
     * @param array<string, mixed> $row
     * @return float|null null when the row has no timestamp that can be read
     */
    private function rowAgeInHours(array $row): ?float {
        $created = strtotime((string) ($row['created_time'] ?? ''));
        if ($created === false) {
            return null;
        }

        $now = strtotime((string) R::getCell('SELECT CURRENT_TIMESTAMP'));
        if ($now === false) {
            return null;
        }

        return ($now - $created) / 3600;
    }

    /**
     * A zone this run has already created counts as existing, even though the
     * dry run never created it -- otherwise the preview shows a create-zone
     * followed by records added to a zone it claims is missing.
     *
     * @var array<string, true>
     */
    private array $pretendCreated = [];

    private function zoneExists(string $zone): bool {
        if ($this->isDryRun()) {
            return isset($this->pretendCreated[$zone]);
        }
        [$code] = $this->pdnsutil('list-zone ' . escapeshellarg($zone));
        return $code === 0;
    }

    /**
     * @return array{0: int, 1: string[]} exit code, output lines
     */
    private function pdnsutil(string $args): array {
        $command = escapeshellcmd($this->pdnsutil) . ' ' . $args;

        if ($this->isDryRun()) {
            // straight to stdout: the invocations are the point of the exercise
            echo $command, "\n";
            if (str_starts_with($args, 'create-zone ')) {
                $this->pretendCreated[$this->zoneArgument($args)] = true;
            }
            return [0, []];
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        return [$code, $output];
    }

    /**
     * The zone name out of a built argument string, for the dry run's
     * bookkeeping. Single-quoted by escapeshellarg().
     */
    private function zoneArgument(string $args): string {
        return preg_match("/^create-zone '([^']*)'/", $args, $m) === 1 ? $m[1] : '';
    }
}
