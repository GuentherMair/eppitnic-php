<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Client;
use Eppitnic\Config;
use Eppitnic\Epp\Session;
use Eppitnic\Support\PasswordGenerator;
use RedBeanPHP\R;

/**
 * Changing the shared EPP registry credential, and recovering when a change
 * does not finish. PasswordGenerator makes the password; this carries it to
 * the registry and keeps that account and the `epp` setting in step.
 *
 * @category    Net
 * @package     Eppitnic\Service\RegistryPasswordChange
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RegistryPasswordChange
{
    /**
     * A test seam, not a configuration point: production leaves it null and
     * gets a fresh Client per call, which the rotation needs anyway -- it makes
     * up to three logins, and a Client carries one session's state.
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
     * Rotate the password when `passwdReminder` says it is near expiry -- as the
     * <login> itself, so call it after any session work. The candidate is
     * written before it is sent, and one attempt per 24h.
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

        $outcome = self::apply(null, true);
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
     * Change the registry password, recording the candidate before sending it:
     * send first and a crash is a lockout, write first and reconcile() can
     * settle it. Not through EppSession::run() -- it rides on the <login>.
     *
     * @param string|null $newPassword the new credential; null generates one
     *                    with PasswordGenerator, at EPP's 16-character ceiling
     *                    for pwType. A caller-supplied one is sent as given --
     *                    the registry is the authority on what it will accept.
     * @param bool $stampAttempt also record the attempt time, which the
     *             once-per-24h rotation limit reads
     * @return array{ok: bool, error: string, stage: string} stage is 'persist'
     *         (nothing was sent), 'connect', 'registry', or '' on success
     */
    public static function apply(?string $newPassword = null, bool $stampAttempt = false): array {
        $newPassword ??= PasswordGenerator::forRegistry();

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
     * Adopt $password without changing anything at the registry -- for `config
     * epp-password --force`. Verified by a real login first: a setting the
     * registry disagrees with breaks every call until someone notices.
     *
     * @return array{ok: bool, error: string}
     */
    public static function adopt(string $password): array {
        if ( ! self::passwordWorks($password)) {
            return ['ok' => false, 'error' => 'the registry did not accept this password -- nothing was changed locally'];
        }

        $epp = Config::get('epp');
        $epp['password'] = $password;
        $epp['lastPasswordUpdate'] = time();
        unset($epp['pendingPassword']);
        Config::set('epp', $epp);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Work out which password the registry holds after a rotation that did not
     * finish. Only it knows, so this asks: log in with the candidate, then, if
     * that is refused, with the stored one.
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

        // Neither works, so this is not a half-finished rotation but something
        // else (locked account, endpoint down, IP not authorised). Keep the
        // candidate: it may well be the live one.
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
