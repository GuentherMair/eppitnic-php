<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;

/**
 * Run something against a logged-in registry session.
 *
 * With `keepalive` off -- the default -- this is connect-per-request: hello,
 * login, $fn, always logout. With it on, $fn runs against the shared session
 * SessionState tracks, opening it only when there is none fresh, and never
 * logging out -- `eppitnic session keepalive` is what closes it, never this.
 *
 * Only reach for this from a handler that genuinely needs a live round trip.
 *
 * @category    Net
 * @package     Eppitnic\Service\EppSession
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class EppSession
{
    // No run()-plus-502 wrapper for the routes, deliberately: each catch is one
    // line already, a [result, ?Response] tuple would hide the control flow,
    // and GET /v1/domains/{name} falls back to the local row instead of a 502

    // -----------------------------------------------------------------
    // EPP session lifecycle
    // -----------------------------------------------------------------

    /**
     * Run $fn against a logged-in EPP session -- see the class docblock for
     * what "logged in" costs under each value of `keepalive`.
     *
     * @param callable $fn function(Client $nic, Session $session)
     * @param bool $debug turn on EPP diagnostics for everything built from this
     *                    session -- see AbstractObject::$debug. Set from the
     *                    caller's `users`.`debug` column, via actor()['debug'].
     * @param Client|null $client use this client instead of building one, and
     *                    keep whatever $client->keepalive it already carries
     *                    rather than computing one. Only the test suite passes
     *                    it, to substitute the transport, and Command's
     *                    --dry-run client, which must never join the shared
     *                    session (Command::withSession() sets it false); a
     *                    production request always wants a fresh Client, which
     *                    is free to join the shared session.
     * @return mixed whatever $fn returns
     * @throws \RuntimeException if hello() or login() fails, or (keepalive
     *         only) the transport failed outright -- see
     *         AbstractObject::sendAndParse()
     */
    public static function run(callable $fn, bool $debug = false, ?Client $client = null): mixed {
        $nic = $client ?? new Client();
        // set before anything is constructed from it: AbstractObject copies
        // this at construction, so a later change would not reach the objects
        $nic->debug = $debug;

        if ($client === null) {
            // Keepalive is only for the account this installation talks to by
            // default -- never a server override (DomainRestoreCommand's
            // "-deleted" endpoint) or a Client built outside this method
            // (RegistryPasswordChange probes a password it may expect to
            // fail, and must never touch a session other callers share).
            $nic->keepalive = SessionState::enabled()
                && $nic->EPPCfg->server === Config::get('epp')['server'];
        }

        $session = new Session($nic);

        if ($nic->keepalive) {
            // Locked (when `session_serialize` opts into it) so two workers
            // racing a stale timestamp cannot both decide to open a session --
            // isFresh() is re-checked inside the lock, not just before it, so
            // the second one in just resumes what the first opened.
            SessionLock::around(SessionState::serializeEnabled(), function () use ($nic, $session) {
                if (SessionState::isFresh()) {
                    $nic->seedCookies(SessionState::cookies());
                    return;
                }
                self::openSession($session);
            });
        } else {
            self::openSession($session);
        }

        try {
            return $fn($nic, $session);
        } finally {
            if ( ! $nic->keepalive) {
                $session->logout();
            }
        }
    }

    /**
     * hello() + login(), each throwing the same \RuntimeException a failure
     * always has -- the one every route already turns into a 502
     * (docs/API.md), and every CLI command into SessionError.
     */
    private static function openSession(Session $session): void {
        if ( ! $session->hello()) {
            throw new \RuntimeException('EPP session unavailable: connection failed');
        }
        if ($session->login() === FALSE) {
            throw new \RuntimeException('EPP session unavailable: login failed (' . $session->getError() . ')');
        }
    }

    /**
     * A test seam, not a configuration point: production leaves it null and
     * gets a fresh Client per call, which the rotation needs anyway.
     *
     * @var callable():Client|null
     */
    private static $eppClientFactory = null;
}
