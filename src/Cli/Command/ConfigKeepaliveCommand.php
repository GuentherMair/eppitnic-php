<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Epp\Session;
use Eppitnic\Service\SessionState;

/**
 * Turn the shared, kept-alive registry session on or off -- see
 * docs/INSTALL.md's "Session keep-alive" for what the flag does.
 */
final class ConfigKeepaliveCommand extends Command
{
    public function describe(): string {
        return 'hold one registry session open across processes instead of logging out after each operation';
    }

    public function arguments(): string {
        return '<on|off>';
    }

    public function options(): array {
        // not MUTATING_OPTIONS: this never opens a registry session for its
        // own sake, only a local settings write (and, turning off, a logout
        // of whatever session is already open) -- same reasoning as
        // ConfigEppSetCommand
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $value = $this->arguments[0] ?? null;
        if ( ! in_array($value, ['on', 'off'], true)) {
            throw new UsageError("give 'on' or 'off'");
        }
        $desired = $value === 'on';

        $current = (bool) Config::get('keepalive');
        if ($current === $desired) {
            $this->line("keepalive is already {$value}");
            return 0;
        }

        if ( ! $this->confirm("Turn keepalive {$value}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would turn keepalive {$value}");
            return 0;
        }

        if ( ! $desired && SessionState::timestamp() > 0) {
            $this->closeSharedSession();
        }

        Config::set('keepalive', $desired);

        $this->record("keepalive turned {$value}", ['keepalive' => $desired]);
        return 0;
    }

    /**
     * Close whatever session is open before turning keepalive off. Otherwise
     * it sits idle for up to 300s while the very next operation opens a
     * second one -- and if nic.it caps concurrent sessions per account, that
     * second login is refused, turning "turn keepalive off" into a five
     * minute outage. Clears the stored state regardless of whether the
     * logout itself succeeds: an unreachable registry should still leave
     * this installation's own records clean.
     */
    private function closeSharedSession(): void {
        $nic = $this->client();
        $nic->keepalive = true;
        $nic->seedCookies(SessionState::cookies());

        $ok = (new Session($nic))->logout();
        $this->line($ok
            ? 'registry session closed'
            : 'could not reach the registry -- the session will idle out on its own');

        SessionState::forget();
    }
}
