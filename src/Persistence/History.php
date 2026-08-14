<?php

namespace Eppitnic\Persistence;

use Eppitnic\Api\ClientIp;
use Eppitnic\Api\LoginRateLimit;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail: who did what, and when.
 *
 * Named history rather than changelog because not everything it records is a
 * change. A `security` row notes an event that altered nothing -- an admin
 * retrieving the registry credential, say -- and carries `action` = 'read'.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\History
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class History
{
    // -----------------------------------------------------------------
    // history
    // -----------------------------------------------------------------

    /**
     * Request headers never worth keeping, whatever else is.
     *
     * `authorization` is the live bearer token: writing it here would put a
     * working credential in a table an operator reads casually, and anyone
     * with SELECT on it could then act as whoever was logged. `cookie` is the
     * same problem in a different header. The rest of a request's headers are
     * what makes the entry useful -- user agent, forwarding chain, origin.
     */
    private const REDACTED_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    /**
     * record an audit-trail entry
     *
     * @param string $object contacts/domains/users/security
     * @param int $object_id the object's DB row id; for `security`, the acting user
     * @param string $action create/update/delete/read
     * @param array $data changed fields (or a minimal identifying set, for create/delete)
     * @param int|null $user_id acting user
     */
    public static function record(
        string $object,
        int $object_id,
        string $action,
        array $data,
        ?int $user_id,
        ?string $network = null
    ): void {
        R::exec("
            INSERT INTO history (user_id, object, object_id, action, network, data)
            VALUES (:user_id, :object, :object_id, :action, :network, :data)
        ", [
            ':user_id'   => $user_id,
            ':object'    => $object,
            ':object_id' => $object_id,
            ':action'    => $action,
            ':network'   => $network,
            ':data'      => json_encode($data),
        ]);
    }

    /**
     * Record something worth knowing about that is not a change to an object,
     * with where the request came from.
     *
     * The address masked to its rate-limiting prefix goes into the `network`
     * column, which is what LoginRateLimit counts.
     *
     * @param string $event what happened, e.g. 'epp_credentials_retrieved'
     * @param Request $request the request that caused it
     * @param int|null $user_id the acting user, also used as object_id so that
     *                 GET /v1/history/security/{id} answers "what did they do".
     *                 Null when there is nobody to attribute it to, which a
     *                 login attempt at an unknown username genuinely is --
     *                 blaming user 1 for it would be a false entry.
     * @param array $detail anything else worth keeping. Must not contain a
     *              secret -- this table is read casually, and by more people
     *              than the thing being recorded was shown to.
     * @param string $action 'read' for a disclosure, 'attempt' for an
     *               authentication that was tried
     */
    public static function recordSecurityEvent(
        string $event,
        Request $request,
        ?int $user_id,
        array $detail = [],
        string $action = 'read'
    ): void {
        self::record(
            'security',
            (int) $user_id,
            $action,
            ['event' => $event, 'ip' => ClientIp::get(), 'headers' => self::safeHeaders($request)] + $detail,
            $user_id,
            LoginRateLimit::network()
        );
    }

    /**
     * The request's headers, less the ones that are themselves credentials.
     *
     * @return array<string, string> header name => value, comma-joined where a
     *         header appeared more than once
     */
    private static function safeHeaders(Request $request): array {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), self::REDACTED_HEADERS, true)) {
                // recorded as present, so that its absence from a log is not
                // mistaken for its absence from the request
                $headers[$name] = '[redacted]';
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }
}
