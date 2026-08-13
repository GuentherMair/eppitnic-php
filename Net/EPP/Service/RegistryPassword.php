<?php

namespace Net\EPP\Service;

use Net\EPP\Epp\Client;
use Net\EPP\Config;
use Net\EPP\Epp\Session;
use RedBeanPHP\R;

/**
 * The shared EPP registry credential: rotating it, and recovering when a
 * rotation does not finish.
 *
 * @category    Net
 * @package     Net\EPP\Service\RegistryPassword
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RegistryPassword
{
    /**
     * How to build a Client for the password rotation.
     *
     * A seam, not a configuration point: the rotation's interesting behaviour
     * is what it does when the registry answers unexpectedly, and production
     * cannot be made to answer unexpectedly on request. Only the test suite
     * sets this; production leaves it null and gets a fresh Client per call,
     * which is what the rotation needs anyway -- it makes up to three separate
     * logins, and a Client carries one session's state.
     *
     * @var callable():Client|null
     */
    private static $clientFactory = null;

    /**
     * Test-suite only. Pass null to restore the production behaviour.
     *
     * @param callable():Client|null $factory
     */
    public static function useClientFactory(?callable $factory): void {
        self::$clientFactory = $factory;
    }

    private static function newClient(): Client {
        return self::$clientFactory !== null ? (self::$clientFactory)() : new Client();
    }

    /**
     * Act on any unacknowledged `passwdReminder` poll message by rotating the
     * shared EPP registry password.
     *
     * The registry warns, through the poll queue, that the account password is
     * approaching expiry; Session::parsePollReq() already recognises and stores
     * those messages, but nothing acted on them, so the warning just piled up
     * until the credential expired and every EPP call started failing.
     *
     * This cannot go through withEppSession(): the new password is carried by
     * the EPP <login> command itself (Session::login($newPW)), so the rotation
     * has to *be* the login, not something done inside an existing session.
     * Call it after any withEppSession() work has finished and logged out.
     *
     * The candidate password is written to the `epp` setting as
     * `pendingPassword` *before* it is sent to the registry, and promoted to
     * `password` once the registry has accepted it. The dangerous window is
     * between those two -- the registry has changed the credential and this
     * installation has not recorded it -- and writing first makes that window
     * survivable: the value is already on disk, so reconcile()
     * can work out which of the two the registry now holds. Nothing has to be
     * recovered from a log, and no credential is written to one.
     *
     * At most one rotation is attempted per 24 hours, tracked by the `epp`
     * setting's `lastPasswordUpdate` (a unix timestamp), also stamped before
     * the attempt: the registry re-sends its reminder well before the
     * credential actually expires, so a day's wait costs nothing, while
     * retrying minutes later with yet another password would leave a second
     * unreconciled candidate behind.
     *
     * @return array human-readable log lines, in the same style as PollProcessor
     */
    public static function rotateOnReminder(): array {
        $log = [];

        // an unresolved candidate from a previous run has to be settled before
        // a new one is made, or the second would overwrite the first and lose
        // the only record of what the registry might be holding
        $epp = Config::get('epp');
        if ( ! empty($epp['pendingPassword'])) {
            $log = array_merge($log, self::reconcile());
            $epp = Config::get('epp');
            if ( ! empty($epp['pendingPassword'])) {
                $log[] = "  candidate password still unresolved -- not starting another rotation";
                return $log;
            }
        }

        $reminders = R::getAll(
            "SELECT id, data FROM messages WHERE type = 'passwdReminder' AND archived_time IS NULL ORDER BY id ASC"
        );
        if (empty($reminders)) {
            return array_merge($log, ["no outstanding passwdReminder messages"]);
        }
        $log[] = count($reminders) . " passwdReminder message(s) outstanding (registry reports expiry '" . $reminders[0]['data'] . "')";

        $last = (int) ($epp['lastPasswordUpdate'] ?? 0);
        $age = time() - $last;
        if ($last > 0 && $age < 86400) {
            $log[] = "  last rotation attempt was " . round($age / 3600, 1) . "h ago -- skipping (one attempt per 24h)";
            return $log;
        }

        $outcome = self::change(null, true);
        if ( ! $outcome['ok']) {
            $log[] = "  FAILED: " . $outcome['error'];
            $log[] = $outcome['stage'] === 'persist'
                ? "  nothing was sent to the registry -- EPP access is unaffected"
                : "  messages left unacknowledged, will retry after 24h";
            return $log;
        }

        foreach ($reminders as $reminder) {
            R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$reminder['id']]);
        }

        $log[] = "  registry password rotated and stored, " . count($reminders) . " message(s) acknowledged";
        return $log;
    }

    /**
     * Change the shared EPP registry password, recording the candidate before
     * sending it.
     *
     * The order is the substance of this method. The credential lives in two
     * places -- the registry's account and this installation's `epp` setting --
     * and a change has to move both. Whichever moves second defines the failure
     * that is survivable: send first and a crash leaves the registry holding a
     * password nobody here knows, which is a lockout; write first and a crash
     * leaves a candidate on disk that the registry may or may not have taken,
     * which reconcile() can settle by asking.
     *
     * This cannot go through withEppSession(): the change is carried by the EPP
     * <login> command itself, so the rotation has to *be* the login, not
     * something done inside a session that has already logged in.
     *
     * @param string|null $newPassword the new credential; null generates one
     *                    with PasswordService, at EPP's 16-character ceiling
     *                    for pwType. A caller-supplied one is sent as given --
     *                    the registry is the authority on what it will accept.
     * @param bool $stampAttempt also record the attempt time, which the
     *             once-per-24h rotation limit reads
     * @return array{ok: bool, error: string, stage: string} stage is 'persist'
     *         (nothing was sent), 'connect', 'registry', or '' on success
     */
    public static function change(?string $newPassword = null, bool $stampAttempt = false): array {
        $newPassword ??= PasswordService::forRegistry();

        // A failure here is the harmless one: nothing has been sent, so the
        // registry still holds the password this installation still has.
        try {
            $epp = Config::get('epp');
            $epp['pendingPassword'] = $newPassword;
            if ($stampAttempt) {
                $epp['lastPasswordUpdate'] = time();
            }
            Config::set('epp', $epp);
        } catch (\Throwable $e) {
            return ['ok' => false, 'stage' => 'persist',
                    'error' => 'could not record the new password locally (' . $e->getMessage() . ')'];
        }

        $session = new Session(self::newClient());

        if ( ! $session->hello()) {
            self::clearPending();
            return ['ok' => false, 'stage' => 'connect', 'error' => 'registry connection unavailable'];
        }
        if ($session->login($newPassword) === FALSE) {
            $error = $session->getError();
            self::clearPending();
            return ['ok' => false, 'stage' => 'registry',
                    'error' => 'registry rejected the password change (' . $error . ')'];
        }
        $session->logout();

        self::promotePending();

        return ['ok' => true, 'stage' => '', 'error' => ''];
    }

    /**
     * Work out which password the registry is holding, after a rotation that
     * did not finish.
     *
     * Reached when `pendingPassword` is still set at the start of a run, which
     * means the previous attempt died between sending the change and recording
     * the outcome -- the process was killed, the database went away, the
     * response was lost. Either password could be the live one, and the only
     * authority on which is the registry, so this asks it: log in with the
     * candidate, and if that is refused, with the stored one.
     *
     * @return array log lines
     */
    public static function reconcile(): array {
        $epp = Config::get('epp');
        $pending = $epp['pendingPassword'] ?? '';
        if ($pending === '') {
            return [];
        }

        $log = ["an unfinished password rotation was found -- asking the registry which password is live"];

        if (self::passwordWorks($pending)) {
            self::promotePending();
            $log[] = "  the registry accepted the new password: promoted, rotation complete";
            return $log;
        }

        if (self::passwordWorks((string) ($epp['password'] ?? ''))) {
            self::clearPending();
            $log[] = "  the registry still holds the old password: the change never landed, candidate discarded";
            return $log;
        }

        // Neither works. That is not this function's doing -- a rotation that
        // half-succeeded would leave one of the two working -- so it is some
        // other problem (the account is locked, the endpoint is down, the IP
        // is not authorised). Keep the candidate: discarding it here would
        // throw away a password that may well be the live one.
        $log[] = "  CRITICAL: the registry accepted neither password. The candidate is kept in the";
        $log[] = "  'epp' setting as 'pendingPassword'; check the account status with the registry";
        $log[] = "  before running again.";
        return $log;
    }

    /**
     * Whether the registry accepts $password for this account.
     *
     * A plain login and logout -- the credential is being tested, not changed.
     */
    private static function passwordWorks(string $password): bool {
        if ($password === '') {
            return false;
        }

        $nic = self::newClient();
        $nic->EPPCfg->password = $password;
        $session = new Session($nic);

        if ( ! $session->hello() || $session->login() === FALSE) {
            return false;
        }
        $session->logout();
        return true;
    }

    /**
     * Make the candidate the password of record.
     */
    private static function promotePending(): void {
        $epp = Config::get('epp');
        if (empty($epp['pendingPassword'])) {
            return;
        }
        $epp['password'] = $epp['pendingPassword'];
        unset($epp['pendingPassword']);
        Config::set('epp', $epp);
    }

    /**
     * Drop the candidate, the registry having refused it.
     */
    private static function clearPending(): void {
        $epp = Config::get('epp');
        if ( ! array_key_exists('pendingPassword', $epp)) {
            return;
        }
        unset($epp['pendingPassword']);
        Config::set('epp', $epp);
    }
}
