<?php

namespace Eppitnic\Selftest;

use Eppitnic\Config;

/**
 * Refuses to let the self-test near production.
 *
 * The self-test registers, alters and deletes real objects. Against
 * `epp.nic.it` that is somebody's domain portfolio and a bill; against the
 * public test registry it is free and disposable. Nothing else in this
 * codebase distinguishes the two -- every other command is supposed to work
 * against both -- so the distinction has to be made here, before a session is
 * opened.
 *
 * The list is an *allowlist*. A denylist naming `epp.nic.it` would let any
 * endpoint nobody thought of through, including a second production host the
 * registry might add; this way an unrecognised endpoint is refused and
 * somebody has to say so deliberately.
 *
 * There is no flag to override it. An override is the only feature here that
 * could destroy data, and a self-test is never so urgent that it needs one.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Guard
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Guard
{
    /**
     * The endpoints the self-test may talk to, by host.
     *
     * Compared against the parsed host, never as a substring of the URL:
     * `https://epp.nic.it/?see=epp.pubtest.nic.it` contains the test host and
     * is production.
     */
    public const TEST_HOSTS = [
        'epp.pubtest.nic.it',
    ];

    /**
     * The EPP endpoint currently configured, as a URL.
     *
     * Read from the setting rather than from a Client: building one connects
     * to nothing, but it does set the process timezone and read half a dozen
     * further settings, and the guard should be answerable before any of that.
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
     * Let the run proceed, or refuse it.
     *
     * Note this vouches for `epp.server` only. `epp.server_deleted` is a
     * separate host reached by passing it to Client explicitly (see
     * DomainRestoreCommand), and no scenario here touches it -- one that did
     * would have to be guarded on that endpoint too.
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
