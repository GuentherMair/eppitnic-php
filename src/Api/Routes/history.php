<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail, newest first, scoped by History::visibleTo(); filters narrow
 * and never widen, so `object=security` as a non-admin returns nothing, not
 * 403. Admins also get `outstanding`, for badging.
 *
 * Filters: object, object_id, action, network, acknowledged, since, until,
 * before_id/after_id (cursors, not counted in total), limit (max 1000), offset.
 */
$app->get('/v1/history', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'isAdmin' => $isAdmin] = Auth::actor($request);

    $page = History::visibleTo($scope, $request->getQueryParams());

    $body = [
        'history' => $page['rows'],
        'total'   => $page['total'],
    ];
    if ($isAdmin) {
        $body['outstanding'] = History::outstandingSecurityCount();
    }

    return Json::response($response, $body);
});

/**
 * Mark one entry as reviewed, recording who and when rather than a flag --
 * "somebody decided this was fine" is not an answer. Not an undo, and
 * re-acknowledging re-stamps it, so the last reader is the one on record.
 */
$app->post('/v1/history/{id}/acknowledge', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $id = (int) $args['id'];

    if ( ! R::getCell('SELECT id FROM history WHERE id = ?', [$id])) {
        return Json::response($response, ['error' => "History entry {$id} not found"], 404);
    }

    R::exec(
        'UPDATE history SET acknowledged_time = CURRENT_TIMESTAMP, acknowledged_user_id = :user WHERE id = :id',
        [':user' => $user_id, ':id' => $id]
    );

    return Json::response($response, [
        'acknowledged' => true,
        'id'           => $id,
        'entry'        => R::getRow('SELECT * FROM history WHERE id = ?', [$id]),
    ]);
});

/**
 * Acknowledge in one go, either the entries named by {"ids": [...]} -- what
 * a screen shows, exactly -- or every entry still unacknowledged up to a
 * moment the caller names: the newest entry they have looked at, so whatever
 * arrived after they loaded the list stays outstanding instead of being waved
 * through unread. Entries already acknowledged keep their original stamp.
 *
 * Moment mode: {"until": "YYYY-MM-DD HH:MM:SS"}, compared to the entry's
 * timestamp inclusively, and optionally {"actions": [...]} to acknowledge only
 * entries of those actions. `timestamp` is only second-resolution, so a burst
 * can land several rows in the same second as the one the caller saw; pass
 * {"until_id": N} (the id of that row) for an exact cutoff -- ids are monotonic.
 * {"object": ...} defaults to `security`: that is the only object type
 * `outstanding` counts, and the only one the dashboard's button clears.
 */
$app->post('/v1/history/acknowledge', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];
    // one stamp for every row, so the caller can show it without reloading
    $now = (string) R::getCell('SELECT CURRENT_TIMESTAMP');
    $stamp = 'UPDATE history SET acknowledged_time = :now, acknowledged_user_id = :user WHERE acknowledged_time IS NULL';

    if (array_key_exists('ids', $body)) {
        $ids = $body['ids'];
        if ( ! is_array($ids) || $ids === []
            || array_filter($ids, fn($id) => filter_var($id, FILTER_VALIDATE_INT) === false) !== []) {
            return Json::response($response, ['error' => 'ids must be a non-empty list of integers'], 400);
        }
        $acknowledged = 0;
        // chunked: a whole cached history is thousands of placeholders
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $bind = [':now' => $now, ':user' => $user_id];
            foreach ($chunk as $i => $id) {
                $bind[":id{$i}"] = $id;
            }
            $names = implode(', ', array_slice(array_keys($bind), 2));
            $acknowledged += R::exec("{$stamp} AND id IN ({$names})", $bind);
        }
        return Json::response($response, [
            'acknowledged'         => $acknowledged,
            'acknowledged_time'    => $now,
            'acknowledged_user_id' => $user_id,
            'outstanding'          => History::outstandingSecurityCount(),
        ]);
    }

    $until = (string) ($body['until'] ?? '');
    if ( ! Validate::isDatetime($until)) {
        return Json::response($response, ['error' => "until must be a datetime such as '2026-09-21 14:41:36'"], 400);
    }

    $untilId = $body['until_id'] ?? null;
    if ($untilId !== null && filter_var($untilId, FILTER_VALIDATE_INT) === false) {
        return Json::response($response, ['error' => 'until_id must be an integer'], 400);
    }

    $object = (string) ($body['object'] ?? 'security');
    if ( ! in_array($object, History::OBJECTS, true)) {
        return Json::response($response, ['error' => 'object must be one of: ' . implode(', ', History::OBJECTS)], 400);
    }

    $bind = [':now' => $now, ':user' => $user_id, ':object' => $object];
    if ($untilId !== null) {
        $where = 'object = :object AND id <= :until_id';
        $bind[':until_id'] = (int) $untilId;
    } else {
        $where = 'object = :object AND `timestamp` <= :until';
        $bind[':until'] = $until;
    }

    $actions = $body['actions'] ?? null;
    if ($actions !== null) {
        if ( ! is_array($actions) || $actions === [] || array_diff($actions, History::ACTIONS) !== []) {
            return Json::response($response, ['error' => 'actions must be a list of: ' . implode(', ', History::ACTIONS)], 400);
        }
        $names = [];
        foreach (array_values($actions) as $i => $action) {
            $names[] = ":action{$i}";
            $bind[":action{$i}"] = $action;
        }
        $where .= ' AND action IN (' . implode(', ', $names) . ')';
    }

    $acknowledged = R::exec("{$stamp} AND {$where}", $bind);

    return Json::response($response, array_filter([
        'acknowledged'         => (int) $acknowledged,
        'object'               => $object,
        'until'                => $until,
        'until_id'             => $untilId !== null ? (int) $untilId : null,
        'acknowledged_time'    => $now,
        'acknowledged_user_id' => $user_id,
        'outstanding'          => History::outstandingSecurityCount(),
    ], static fn($v) => $v !== null));
});

/**
 * What happened to one object, scoped exactly as the listing is. It used to
 * answer for any object named, so any token could read every user's history --
 * and a `users` snapshot carries an email address and an admin flag.
 */
$app->get('/v1/history/{object}/{object_id}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);

    $page = History::visibleTo($scope, [
        'object'    => $args['object'],
        'object_id' => $args['object_id'],
        'limit'     => $request->getQueryParams()['limit'] ?? 500,
    ]);

    return Json::response($response, [
        'history' => $page['rows'],
        'total'   => $page['total'],
    ]);
});
