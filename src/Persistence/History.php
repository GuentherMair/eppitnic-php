<?php

namespace Eppitnic\Persistence;

use Eppitnic\Api\ClientIp;
use Eppitnic\Api\LoginRateLimit;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail: who did what, and when. History rather than changelog
 * because not everything is a change -- a `security` row can note an event
 * that altered nothing, such as an admin reading the registry credential.
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
     * Headers never worth keeping: `authorization` is a live bearer token, and
     * anyone with SELECT here could then act as whoever was logged; `cookie` is
     * the same problem. The rest is what makes an entry useful.
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
     * Record something that is not a change to an object, with where it came
     * from -- masked to its rate-limiting prefix in `network`, what
     * LoginRateLimit counts.
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
     * Entries $userId may see, newest first: an admin sees everything, everyone
     * else the objects they own, and nobody but an admin sees `security`, which
     * carries other people's addresses and headers.
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
