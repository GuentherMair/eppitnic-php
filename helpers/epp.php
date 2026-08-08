<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';

/**
 * Run $fn against a fresh, logged-in EPP session, then always log out.
 * Connect-per-request, matching every examples/*.php and CLI/*.php script's
 * own hello()/login()/logout() pattern -- only call this from handlers that
 * actually need a live registry round-trip.
 *
 * @param  callable $fn  function(Net_EPP_Client $nic, Net_EPP_IT_Session $session)
 * @return mixed         whatever $fn returns
 * @throws \RuntimeException if hello() or login() fails
 */
function withEppSession(callable $fn) {
    $nic = new Net_EPP_Client();
    $session = new Net_EPP_IT_Session($nic);

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
