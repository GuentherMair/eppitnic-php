<?php

namespace Eppitnic\Service;

use Eppitnic\PowerDns\Api;
use Eppitnic\PowerDns\CurlHttpClient;
use Eppitnic\PowerDns\HttpClient;

/**
 * Prepare a zone in PowerDNS before the registry is asked to delegate to it,
 * so its DNS check finds the nameservers answering at once. Only for
 * nameservers in `pdns.nameservers`; the queued `pdns sync` stays the retry.
 *
 * @category    Net
 * @package     Eppitnic\Service\PowerDnsZones
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PowerDnsZones
{
    /** test seam; a real curl client otherwise */
    private static ?HttpClient $http = null;

    public static function useHttpClient(?HttpClient $http): void {
        self::$http = $http;
    }

    /**
     * Create the zone, or set its apex NS records, on every listed API. A
     * failure is reported, not thrown: the registry is asked anyway.
     *
     * @param string[] $nameservers the NS set the registry is about to get
     * @return array{servers: array, warning: ?string} what undo() needs
     */
    public static function provision(string $zone, array $nameservers): array {
        $servers = self::targets($nameservers);
        $ttl = (int) (CronjobSettings::get('pdns')['ttl'] ?? 3600) ?: 3600;
        $failures = [];

        foreach ($servers as $i => $server) {
            $outcome = $server['api']->syncZone($zone, $nameservers, $ttl);
            $servers[$i]['created'] = $outcome['created'];
            if ( ! $outcome['ok']) {
                $failures[] = "{$server['label']}: {$outcome['message']}";
            }
        }

        $warning = null;
        if ($failures !== []) {
            $warning = "PowerDNS zone for {$zone} could not be prepared (" . implode('; ', $failures)
                . '); the next pdns sync retries it';
            error_log("eppitnic: {$warning}");
        }
        return ['servers' => $servers, 'warning' => $warning];
    }

    /**
     * Take provision() back after the registry refused: delete a zone it
     * created, or give an existing one its previous NS set again.
     *
     * @param array $provisioned provision()'s result
     * @param string[] $previousNameservers the NS set before the change
     */
    public static function undo(string $zone, array $provisioned, array $previousNameservers): void {
        $ttl = (int) (CronjobSettings::get('pdns')['ttl'] ?? 3600) ?: 3600;

        foreach ($provisioned['servers'] as $server) {
            $outcome = ! empty($server['created'])
                ? $server['api']->deleteZone($zone)
                : ($previousNameservers !== []
                    ? $server['api']->replaceApexNs($zone, $previousNameservers, $ttl)
                    : ['ok' => true, 'message' => '']);
            if ( ! $outcome['ok']) {
                error_log("eppitnic: undoing the PowerDNS zone for {$zone} on {$server['label']} failed: {$outcome['message']}");
            }
        }
    }

    /**
     * The APIs to prepare $nameservers' zone on: none unless sync is enabled,
     * an API is listed, and $nameservers include a `pdns.nameservers` entry.
     *
     * @param string[] $nameservers
     * @return array<int, array{label: string, api: Api}>
     */
    private static function targets(array $nameservers): array {
        try {
            $cfg = CronjobSettings::get('pdns');
        } catch (\RuntimeException) {
            return []; // never seeded: nothing to sync to
        }
        $apis = (array) ($cfg['apis'] ?? []);
        if (empty($cfg['enabled']) || $apis === []) {
            return [];
        }

        $ours = (array) ($cfg['nameservers'] ?? []);
        $given = array_map(static fn($ns) => PowerDnsNameservers::normalize((string) $ns), $nameservers);
        if (array_intersect($given, $ours) === []) {
            return [];
        }

        $http = self::$http ?? new CurlHttpClient();
        return array_map(
            static fn(array $server) => ['label' => PowerDnsApis::baseUrl($server), 'api' => new Api($http, $server)],
            $apis
        );
    }
}
