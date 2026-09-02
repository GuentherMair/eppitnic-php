<?php

namespace Eppitnic\Selftest;

use Eppitnic\Config;

/**
 * Refuses to let the self-test near production: it deletes real objects, free
 * against the test registry and somebody's portfolio against epp.nic.it. An
 * allowlist with no override -- an override is the only thing that could hurt.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Guard
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Guard
{
    /**
     * The endpoints the self-test may talk to, by host -- compared against the
     * parsed host, never a substring of the URL:
     * `https://epp.nic.it/?see=epp.pubtest.nic.it` contains one and is not it.
     */
    public const TEST_HOSTS = [
        'epp.pubtest.nic.it',
    ];

    /**
     * The configured EPP endpoint. Read from the setting, not a Client:
     * building one sets the process timezone and reads half a dozen more
     * settings, and the guard should answer before any of that.
     *
     * @return string the `epp.server` setting, '' when unset
     */
    public static function endpoint(): string {
        try {
            $epp = Config::get('epp');
        } catch (\RuntimeException) {
            // never seeded: answer '' so this refuses like any other
            // unusable endpoint rather than failing as a config error
            return '';
        }

        return is_array($epp) ? (string) ($epp['server'] ?? '') : '';
    }

    /**
     * @param string $url an EPP endpoint URL
     * @return bool whether it is one of the registry's test endpoints
     */
    public static function isTestEndpoint(string $url): bool {
        $host = parse_url(trim($url), PHP_URL_HOST);

        // parse_url() answers null for a bare hostname with no scheme, which
        // is a shape somebody may well have typed into the setting
        if ($host === null || $host === false) {
            $host = parse_url('https://' . trim($url), PHP_URL_HOST);
        }

        return is_string($host)
            && in_array(strtolower($host), self::TEST_HOSTS, true);
    }

    /**
     * Let the run proceed, or refuse it. Vouches for `epp.server` only:
     * `epp.server_deleted` is a separate host no scenario here touches, and one
     * that did would have to be guarded on it too.
     *
     * @return string the endpoint the run may use
     * @throws RefusedError if it is not a known test endpoint
     */
    public static function assertTestEnvironment(): string {
        $endpoint = self::endpoint();

        if ($endpoint === '') {
            throw new RefusedError(
                'No EPP endpoint is configured (setting `epp`.`server`), so there is nothing to verify against.'
            );
        }
        if ( ! self::isTestEndpoint($endpoint)) {
            throw new RefusedError(
                "Refusing to run against '{$endpoint}': the self-test creates and deletes real objects, "
                . 'and this is not one of the registry\'s test endpoints ('
                . implode(', ', self::TEST_HOSTS) . '). '
                . 'Point the `epp` setting at the public test registry and try again.'
            );
        }

        return $endpoint;
    }
}
