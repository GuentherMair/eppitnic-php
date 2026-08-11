<?php

use Net\EPP\Helpers;
use Net\EPP\IT\PollProcessor;

/**
 * Cron entry point, in three steps:
 *
 *  1. drain the EPP server's poll queue into the `messages` table,
 *  2. reconcile domain transfer state against it (PollProcessor),
 *  3. act on any `passwdReminder` message by rotating the registry password.
 *
 * None of this can be allowed to happen only when a user interacts with the
 * web GUI/API -- it needs to run on a schedule regardless of user activity.
 *
 * Step 3 runs outside the session opened for steps 1 and 2, and that is not
 * incidental: the EPP protocol carries a new password in the <login> command
 * itself, so changing it means logging in again rather than issuing a command
 * on the session already open. It is also why the step is last -- it
 * invalidates the credential the earlier steps were using.
 *
 * Exit codes: HELLO_FAILED/LOGIN_FAILED (Net/EPP/IT/Session.php) if the EPP
 * session itself couldn't be established; 0 otherwise (per-transfer and
 * password-rotation errors are logged to stdout, not treated as a fatal run
 * failure, since they should simply be retried on the next scheduled run).
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

// step 3: the queue drained above may have just stored a passwdReminder. This
// opens its own session (see the note above) and rate-limits itself to one
// attempt per 24 hours via the `epp` setting's lastPasswordUpdate stamp.
foreach (Helpers::rotateEppPasswordOnReminder() as $line) {
    echo $line . "\n";
}
