<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Session;
use Eppitnic\Service\SessionState;

/**
 * Refresh the shared, kept-alive registry session before nic.it's idle
 * timeout, by sending `hello` -- which nic.it's own documentation names for
 * exactly this: "during a working session to keep the session active and
 * prevent the client from being disconnected due to timeout." Meant to run
 * once a minute from cron; see docker/crontab and docs/INSTALL.md.
 *
 * Prints nothing when there is nothing to do -- `keepalive` off, no session
 * yet, or the session is not due for a refresh -- since that is most runs of
 * a job invoked 1440 times a day.
 */
final class SessionKeepaliveCommand extends Command
{
    public function describe(): string {
        return 'refresh the shared registry session if it is due (a no-op unless `keepalive` is on)';
    }

    public function run(): int {
        $this->database();

        if ( ! SessionState::enabled() || ! SessionState::needsRefresh()) {
            return 0;
        }

        $nic = $this->client();
        $nic->keepalive = true;
        $nic->seedCookies(SessionState::cookies());

        $session = new Session($nic);

        if ($session->hello()) {
            // hello() itself calls SessionState::remember() under keepalive --
            // see Session::hello()
            $this->record('registry session refreshed', ['refreshed' => true]);
            return 0;
        }

        // A failed hello means the registry is unreachable right now, not
        // that the session is gone -- leave the stored state alone. Discarding
        // a valid session over a transient blip would force every operation
        // after it into a needless re-login; the next real command's own
        // 2002/2200/2201 retry is what notices an actually-dead session.
        $this->warn('no greeting from ' . $nic->EPPCfg->server
            . ' -- session state left as is (HTTP ' . ($session->result?->code ?? '-') . ')');
        return HELLO_FAILED;
    }
}
