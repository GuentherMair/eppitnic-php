<?php

namespace Eppitnic\Cli\Command;

/**
 * Show and edit `safe_networks`, the ranges whose logins skip the MFA code
 * (Auth reads it through ClientIp::inCidr).
 */
final class ConfigSafeNetworksCommand extends CidrListCommand
{
    public function describe(): string {
        return 'show or edit safe_networks, the ranges whose logins skip the MFA code';
    }

    protected function key(): string {
        return 'safe_networks';
    }

    protected function emptyMessage(): string {
        return 'safe_networks is empty -- every login needs its MFA code';
    }

    protected function addWarning(): string {
        return 'Logins from these ranges will skip the MFA code entirely.';
    }
}
