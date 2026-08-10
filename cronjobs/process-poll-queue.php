<?php

use Net\EPP\Helpers;
use Net\EPP\IT\PollProcessor;

/**
 * Cron entry point: drains the EPP server's poll queue and reconciles
 * domain transfer state against it (PollProcessor). This can't
 * be allowed to only happen when a user interacts with the web GUI/API --
 * it needs to run on a schedule regardless of user activity.
 *
 * Exit codes: HELLO_FAILED/LOGIN_FAILED (Net/EPP/IT/Session.php) if the EPP
 * session itself couldn't be established; 0 otherwise (per-transfer errors
 * are logged to stdout, not treated as a fatal run failure, since they
 * should simply be retried on the next scheduled run).
 */

// suggested crontab entry, every 5 minutes:
// 0-59/5 * * * *  php /path/to/cronjobs/process-poll-queue.php >> /var/log/eppitnic/poll-queue.log 2>&1

require_once dirname(__FILE__).'/../vendor/autoload.php';
try {
    Helpers::withEppSession(function ($nic, $session) {
        $processor = new PollProcessor($nic);

        foreach ($processor->drainQueue($session) as $line) {
            echo $line . "\n";
        }
        foreach ($processor->verifyTransfer() as $line) {
            echo $line . "\n";
        }
    });
} catch (\RuntimeException $e) {
    echo "EPP session unavailable: " . $e->getMessage() . "\n";
    exit(HELLO_FAILED);
}
