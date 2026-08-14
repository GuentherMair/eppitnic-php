<?php

namespace Eppitnic\Persistence;

use Eppitnic\Api\ClientIp;
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
    public static function record(string $object, int $object_id, string $action, array $data, ?int $user_id): void {
        R::exec("
            INSERT INTO history (user_id, object, object_id, action, data)
            VALUES (:user_id, :object, :object_id, :action, :data)
        ", [
            ':user_id'   => $user_id,
            ':object'    => $object,
            ':object_id' => $object_id,
            ':action'    => $action,
            ':data'      => json_encode($data),
        ]);
    }

    /**
     * Record something that changed nothing but is worth knowing about, with
     * where the request came from.
     *
     * @param string $event what happened, e.g. 'epp_credentials_retrieved'
     * @param Request $request the request that caused it
     * @param int|null $user_id the acting user, also used as object_id so that
     *                 GET /v1/history/security/{id} answers "what did they do"
     * @param array $detail anything else worth keeping. Must not contain a
     *              secret -- this table is read casually, and by more people
     *              than the thing being recorded was shown to.
     */
    public static function recordSecurityEvent(string $event, Request $request, ?int $user_id, array $detail = []): void {
        self::record('security', (int) $user_id, 'read', [
            'event'   => $event,
            'ip'      => ClientIp::get() ?: null,
            'headers' => self::safeHeaders($request),
        ] + $detail, $user_id);
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
