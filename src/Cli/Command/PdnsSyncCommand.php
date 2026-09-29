<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\PowerDns\Api;
use Eppitnic\PowerDns\CurlHttpClient;
use Eppitnic\PowerDns\DryRunHttpClient;
use Eppitnic\PowerDns\HttpClient;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Service\PowerDnsApis;
use RedBeanPHP\R;

/**
 * Apply pending DNS-sync events to every server in the `pdns` setting's
 * `apis` list, over the PowerDNS authoritative HTTP API. Every server gets
 * every change; a row is `applied` only once all of them succeed, else it
 * stays active with a per-server message in `exit_message`. create and
 * update share one idempotent path; a delete waits `--delay-hours`. A
 * no-op while `pdns.enabled` is off, or `apis` is empty -- see
 * `config pdns-set enabled true` and `config pdns-api add`.
 *
 *   0-59/15 * * * *  /path/to/bin/eppitnic pdns sync >> /var/log/eppitnic/pdns-sync.log 2>&1
 */
final class PdnsSyncCommand extends Command
{
    public function describe(): string {
        return 'apply pending DNS-sync events to PowerDNS via its HTTP API';
    }

    public function options(): array {
        return [
            'delay-hours=' => 'hours to wait before applying a delete (default: pdns.delay_hours)',
            'dry-run'      => 'print the requests that would be sent to each PowerDNS API, without sending them',
        ];
    }

    private int $ttl = 3600;

    /** test seam; overrides both the real curl client and the dry-run one */
    private ?HttpClient $httpClient = null;

    public function setHttpClient(HttpClient $client): void {
        $this->httpClient = $client;
    }

    public function run(): int {
        $this->database();

        $cfg = CronjobSettings::get('pdns');
        if ( ! $cfg['enabled']) {
            $this->line('pdns sync is off (see: eppitnic config pdns-set enabled true)');
            return 0;
        }

        $servers = (array) ($cfg['apis'] ?? []);
        if ($servers === []) {
            $this->line('pdns sync is on but no PowerDNS API is configured (see: eppitnic config pdns-api add)');
            return 0;
        }

        $delayHours = (int) $this->option('delay-hours', $cfg['delay_hours']);
        $this->ttl = (int) $cfg['ttl'];

        // an injected client (tests) always wins; otherwise --dry-run gets a
        // client that answers itself, so the real one is used nowhere else
        $dryRunHttp = $this->httpClient === null && $this->isDryRun() ? new DryRunHttpClient() : null;
        $http = $this->httpClient ?? $dryRunHttp ?? new CurlHttpClient();

        $apis = array_map(
            static fn(array $server) => ['label' => PowerDnsApis::baseUrl($server), 'api' => new Api($http, $server)],
            $servers
        );

        $rows = R::getAll("SELECT * FROM tasks WHERE object = 'pdns' AND active = 1 AND date <= CURRENT_DATE ORDER BY id ASC");
        if ($rows === []) {
            $this->line('no pending DNS-sync events');
            return 0;
        }

        $applied = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $outcome = $row['action'] === 'delete'
                ? $this->applyDelete($row, $delayHours, $apis)
                : $this->applyUpsert($row, $apis);

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

            // deferred: not attempted this run (still waiting out
            // --delay-hours), so there is no result to record
            if ($outcome['status'] === 'deferred') {
                continue;
            }

            // only a success is terminal -- a failure or a skip records what
            // was found, but stays active so the next run tries again. Under
            // --dry-run nothing actually ran, so the row is left untouched.
            if ( ! $this->isDryRun()) {
                R::exec(
                    "UPDATE tasks SET executed_time = CURRENT_TIMESTAMP, exit_code = ?, exit_message = ?"
                    . ($outcome['status'] === 'applied' ? ", active = 0" : "") . " WHERE id = ?",
                    [$outcome['status'] === 'applied' ? 0 : 1, $outcome['message'], $row['id']]
                );
            }

            if ($outcome['status'] === 'failed') {
                $failed++;
                continue;
            }
            if ($outcome['status'] === 'applied') {
                $applied++;
            }
        }

        if ($dryRunHttp !== null) {
            $this->printDryRun($dryRunHttp);
        }

        $this->line('');
        $this->line(sprintf('%d event(s) %s, %d failed', $applied,
            $this->isDryRun() ? 'would be applied' : 'applied', $failed));

        return $failed > 0 ? DNS_SYNC_FAILED : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{label: string, api: Api}> $apis
     * @return array{status: string, message: string}
     */
    private function applyDelete(array $row, int $delayHours, array $apis): array {
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

        $zone = (string) $row['domain'];
        $failures = [];
        foreach ($apis as $server) {
            $outcome = $server['api']->deleteZone($zone);
            if ( ! $outcome['ok']) {
                $failures[] = "{$server['label']}: {$outcome['message']}";
            }
        }

        if ($failures !== []) {
            return ['status' => 'failed', 'message' => 'FAILED: ' . implode('; ', $failures)];
        }
        return ['status' => 'applied', 'message' => 'zone deleted'];
    }

    /**
     * create and update, which are the same operation -- see the class note.
     *
     * @param array<string, mixed> $row
     * @param array<int, array{label: string, api: Api}> $apis
     * @return array{status: string, message: string}
     */
    private function applyUpsert(array $row, array $apis): array {
        $zone = (string) $row['domain'];

        $domainRow = R::getRow("SELECT ns FROM domains WHERE domain = ?", [$zone]);
        if (empty($domainRow)) {
            return ['status' => 'skipped', 'message' => 'SKIPPED: domain not found locally'];
        }

        $nameservers = array_keys(SerializedColumn::toArray($domainRow['ns'] ?? null));
        if ($nameservers === []) {
            return ['status' => 'skipped', 'message' => 'SKIPPED: domain has no nameservers on record'];
        }

        $failures = [];
        foreach ($apis as $server) {
            $outcome = $server['api']->syncZone($zone, $nameservers, $this->ttl);
            if ( ! $outcome['ok']) {
                $failures[] = "{$server['label']}: {$outcome['message']}";
            }
        }

        if ($failures !== []) {
            return ['status' => 'failed', 'message' => 'FAILED: ' . implode('; ', $failures)];
        }
        return ['status' => 'applied', 'message' => 'zone synced (' . count($nameservers) . ' NS records)'];
    }

    /**
     * How long ago the row was written, by the database's own clock at both
     * ends: `created_time` is a CURRENT_TIMESTAMP default, so comparing it
     * against PHP's would make the grace period a timezone question.
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

    /** Every request a dry run built, never the API key (headers are not printed). */
    private function printDryRun(DryRunHttpClient $client): void {
        foreach ($client->sentRequests() as $request) {
            // straight to stdout: the requests are the point of the exercise
            echo $request['method'], ' ', $request['url'], "\n";
            if ($request['body'] !== null) {
                echo $request['body'], "\n";
            }
        }
    }
}
