<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;

/**
 * Run something against a logged-in registry session, and always log out.
 *
 * Connect-per-request: only reach for this from a handler that genuinely needs
 * a live round trip.
 *
 * @category    Net
 * @package     Eppitnic\Service\EppSession
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class EppSession
{
    // -----------------------------------------------------------------
    // EPP session lifecycle
    // -----------------------------------------------------------------

    /**
     * Run $fn against a fresh, logged-in EPP session, then always log out.
     * Connect-per-request, matching every examples/*.php and CLI/*.php script's
     * own hello()/login()/logout() pattern -- only call this from handlers that
     * actually need a live registry round-trip.
     *
     * @param callable $fn function(Client $nic, Session $session)
     * @param bool $debug turn on EPP diagnostics for everything built from this
     *                    session -- see AbstractObject::$debug. Set from the
     *                    caller's `users`.`debug` column, via actor()['debug'].
     * @param Client|null $client use this client instead of building one. Only
     *                    the test suite passes it, to substitute the transport;
     *                    production always wants a fresh connect-per-request.
     * @return mixed whatever $fn returns
     * @throws \RuntimeException if hello() or login() fails
     */
    public static function run(callable $fn, bool $debug = false, ?Client $client = null): mixed {
        $nic = $client ?? new Client();
        // set before anything is constructed from it: AbstractObject copies
        // this at construction, so a later change would not reach the objects
        $nic->debug = $debug;

        $session = new Session($nic);

        if ( ! $session->hello()) {
            throw new \RuntimeException('EPP session unavailable: connection failed');
        }
        if ($session->login() === FALSE) {
            throw new \RuntimeException('EPP session unavailable: login failed (' . $session->getError() . ')');
        }

        try {
            return $fn($nic, $session);
        } finally {
            $session->logout();
        }
    }

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
    private static $eppClientFactory = null;
}
