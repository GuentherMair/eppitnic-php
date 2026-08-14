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
    /**
     * Entries $userId is allowed to see, newest first.
     *
     * An admin sees everything. Everyone else sees the history of the objects
     * they own, by the same rule the rest of the API scopes by: their own user
     * row, their own domains, their own contacts. Never `security`, which
     * carries other people's addresses and headers.
     *
     * This exists because the per-object endpoint used to answer for any
     * object anybody asked about -- a `users` snapshot carries an email address
     * and an admin flag, and any valid token could read every one of them.
     *
     * @param array<string, mixed> $filters object, object_id, action, network,
     *        acknowledged ('0'/'1'), since, until, limit, offset
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function visibleTo(int $userId, bool $isAdmin, array $filters = []): array {
        [$where, $params] = self::scope($userId, $isAdmin, $filters);

        $limit  = min(500, max(1, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return [
            'rows'  => R::getAll(
                "SELECT * FROM history WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            ),
            'total' => (int) R::getCell("SELECT COUNT(*) FROM history WHERE {$where}", $params),
        ];
    }

    /**
     * How many `security` entries nobody has acknowledged. Admin-only figure,
     * since admins are the only ones who can see those entries at all.
     */
    public static function outstandingSecurityCount(): int {
        return (int) R::getCell(
            "SELECT COUNT(*) FROM history WHERE object = 'security' AND acknowledged_time IS NULL"
        );
    }

    /**
     * The WHERE clause and its parameters: what this caller may see, narrowed
     * by whatever they asked for.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function scope(int $userId, bool $isAdmin, array $filters): array {
        $params = [];
        $clauses = [];

        if ($isAdmin) {
            $clauses[] = '1 = 1';
        } else {
            // security is excluded by omission rather than by a NOT: a new
            // object type defaults to invisible until it is listed here
            $params[':me'] = $userId;
            $clauses[] = "(object = 'users' AND object_id = :me
                        OR object = 'domains'  AND object_id IN (SELECT id FROM domains  WHERE user_id = :me)
                        OR object = 'contacts' AND object_id IN (SELECT id FROM contacts WHERE user_id = :me))";
        }

        foreach (['object' => 'object', 'object_id' => 'object_id', 'action' => 'action', 'network' => 'network'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $clauses[] = "{$column} = :{$filter}";
                $params[":{$filter}"] = $filters[$filter];
            }
        }

        if (isset($filters['acknowledged'])) {
            $clauses[] = $filters['acknowledged'] === '0'
                ? 'acknowledged_time IS NULL'
                : 'acknowledged_time IS NOT NULL';
        }

        foreach (['since' => '>=', 'until' => '<='] as $filter => $comparison) {
            if ( ! empty($filters[$filter])) {
                $clauses[] = "`timestamp` {$comparison} :{$filter}";
                $params[":{$filter}"] = $filters[$filter];
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

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
