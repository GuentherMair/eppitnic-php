<?php

/**
 * Cron entry point: applies pending DNS-sync events from the `reminder`
 * table (action = create/update/delete, written by routes/domain.php and
 * Net_EPP_IT_PollProcessor) to a PowerDNS authoritative server.
 *
 * This talks to PowerDNS exclusively through `pdnsutil` shell invocations
 * -- never a direct database connection to PowerDNS's own backend, so it
 * works regardless of which backend PowerDNS itself is configured with.
 * The environment this runs in must already have `pdnsutil` on the PATH
 * (or configured via the `pdnsutil_path` config key) with permission to
 * manage zones; that's an operator/deployment concern, not something this
 * script can arrange for itself.
 *
 * NOTE: the exact `pdnsutil` subcommand syntax below (create-zone,
 * delete-zone, add-record, delete-rrset) matches long-standing, documented
 * PowerDNS commands, but minor argument-shape differences do exist between
 * PowerDNS versions -- verify against the target server's actual version
 * before relying on this in production.
 *
 * create/update: applied immediately and share the same handling --
 * ensure the zone exists (pdnsutil create-zone, seeded with the domain's
 * current nameservers), then unconditionally reconcile the zone's apex NS
 * record set to match `domains.ns`. Idempotent and correct whether the
 * zone already existed or not, so there's no need to treat create/update
 * as genuinely different operations here.
 *
 * delete: only applied once `created_time` is at least `--delay-hours`
 * (default 12) in the past -- a grace period before the zone is torn down.
 * Rows not yet due are left untouched for a later run.
 *
 * Every successful pdnsutil call (per row) archives the row (active = 0);
 * a failure leaves it active for retry on the next run and logs the error.
 *
 * Suggested crontab entry (every 15 minutes):
 * 0-59/15 * * * *  php /path/to/cronjobs/pdnsutil_updates.php >> /var/log/eppitnic/pdnsutil-updates.log 2>&1
 *
 * Usage: php pdnsutil_updates.php [--delay-hours=N]
 */

require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__).'/../helpers/db.php';

use RedBeanPHP\R;

$options = getopt('', ['delay-hours::']);
$delayHours = isset($options['delay-hours']) ? (int) $options['delay-hours'] : 12;

$pdnsutil = getConfig('pdnsutil_path') ?: 'pdnsutil';
$ttl = (int) (getConfig('pdnsutil_ttl') ?: 3600);

/**
 * run a pdnsutil subcommand, returning [exitCode, outputLines]
 */
function pdnsutilExec(string $pdnsutil, string $args): array {
    $output = [];
    $exitCode = 0;
    exec(escapeshellcmd($pdnsutil) . ' ' . $args . ' 2>&1', $output, $exitCode);
    return [$exitCode, $output];
}

function zoneExists(string $pdnsutil, string $zone): bool {
    [$exitCode] = pdnsutilExec($pdnsutil, 'list-zone ' . escapeshellarg($zone));
    return $exitCode === 0;
}

$rows = R::getAll("SELECT * FROM reminder WHERE active = 1 AND action IS NOT NULL ORDER BY id ASC");

foreach ($rows as $row) {
    $zone = $row['domain'];
    echo "[{$row['id']}] {$zone} ({$row['action']}): ";

    if ($row['action'] === 'delete') {
        $due = (bool) R::getCell("SELECT created_time <= NOW() - INTERVAL ? HOUR FROM reminder WHERE id = ?", [$delayHours, $row['id']]);
        if ( ! $due) {
            echo "not due yet (delay {$delayHours}h)\n";
            continue;
        }

        [$exitCode, $output] = pdnsutilExec($pdnsutil, 'delete-zone ' . escapeshellarg($zone));
        if ($exitCode !== 0) {
            echo "FAILED: " . implode(' ', $output) . "\n";
            continue;
        }
        echo "zone deleted\n";
        R::exec("UPDATE reminder SET active = 0 WHERE id = ?", [$row['id']]);
        continue;
    }

    // create / update share identical handling -- see docblock above
    $domainRow = R::getRow("SELECT ns FROM domains WHERE domain = ?", [$zone]);
    if (empty($domainRow)) {
        echo "SKIPPED: domain not found locally\n";
        continue;
    }
    $nsData = empty($domainRow['ns']) ? [] : unserialize($domainRow['ns']);
    $nameservers = array_keys((array) $nsData);
    if (empty($nameservers)) {
        echo "SKIPPED: domain has no nameservers on record\n";
        continue;
    }

    if ( ! zoneExists($pdnsutil, $zone)) {
        $nsArgs = implode(' ', array_map('escapeshellarg', $nameservers));
        [$exitCode, $output] = pdnsutilExec($pdnsutil, 'create-zone ' . escapeshellarg($zone) . ' ' . $nsArgs);
        if ($exitCode !== 0) {
            echo "FAILED to create zone: " . implode(' ', $output) . "\n";
            continue;
        }
    }

    [$exitCode, $output] = pdnsutilExec($pdnsutil, 'delete-rrset ' . escapeshellarg($zone) . ' ' . escapeshellarg($zone) . ' NS');
    if ($exitCode !== 0) {
        echo "FAILED to clear apex NS records: " . implode(' ', $output) . "\n";
        continue;
    }

    $failed = false;
    foreach ($nameservers as $ns) {
        $content = escapeshellarg(rtrim($ns, '.') . '.');
        [$exitCode, $output] = pdnsutilExec($pdnsutil, 'add-record ' . escapeshellarg($zone) . ' ' . escapeshellarg($zone) . " NS {$ttl} {$content}");
        if ($exitCode !== 0) {
            echo "FAILED to add NS record {$ns}: " . implode(' ', $output) . "\n";
            $failed = true;
            break;
        }
    }
    if ($failed) {
        continue;
    }

    echo "zone synced (" . count($nameservers) . " NS records)\n";
    R::exec("UPDATE reminder SET active = 0 WHERE id = ?", [$row['id']]);
}
