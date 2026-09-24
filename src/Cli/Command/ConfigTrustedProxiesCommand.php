<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Service\TrustedProxies;

/**
 * Show and edit `trusted_proxies`, the peers whose X-Forwarded-For (and
 * remote-auth header) is believed. Written through TrustedProxies, which
 * also refuses catch-all ranges and records the change to `history`.
 */
final class ConfigTrustedProxiesCommand extends CidrListCommand
{
    public function describe(): string {
        return 'show or edit trusted_proxies, the reverse proxies whose forwarded headers are believed';
    }

    protected function key(): string {
        return 'trusted_proxies';
    }

    protected function emptyMessage(): string {
        return 'trusted_proxies is empty -- no forwarded header is believed from anyone';
    }

    protected function addWarning(): string {
        return 'These peers may tell eppitnic which client a request came from, '
            . 'and under remote authentication in header mode, who is logged in.';
    }

    protected function validate(array $desired): void {
        try {
            TrustedProxies::normalize($desired);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
    }

    protected function persist(array $desired): void {
        TrustedProxies::set($desired, $this->userId());
    }
}
