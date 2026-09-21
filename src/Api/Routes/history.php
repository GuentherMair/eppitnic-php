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
 * limit (max 500), offset.
 */
$app->get('/v1/history', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Auth::actor($request);

    $page = History::visibleTo($user_id, $isAdmin, $request->getQueryParams());

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
 * Acknowledge every entry still unacknowledged up to a moment the caller names,
 * in one go. The moment is what keeps it honest: it is the newest entry the
 * caller has looked at, so whatever arrived after they loaded the list stays
 * outstanding instead of being waved through unread. Entries already
 * acknowledged keep their original stamp.
 *
 * Body: {"until": "YYYY-MM-DD HH:MM:SS"}, compared to the entry's timestamp
 * inclusively, and optionally {"actions": [...]} to acknowledge only entries
 * of those actions -- what a screen that lists just the severe ones needs.
 */
$app->post('/v1/history/acknowledge', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];
    $until = (string) ($body['until'] ?? '');

    if ( ! Validate::isDatetime($until)) {
        return Json::response($response, ['error' => "until must be a datetime such as '2026-09-21 14:41:36'"], 400);
    }

    $where = 'acknowledged_time IS NULL AND `timestamp` <= :until';
    $bind = [':user' => $user_id, ':until' => $until];

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

    $acknowledged = R::exec(
        "UPDATE history SET acknowledged_time = CURRENT_TIMESTAMP, acknowledged_user_id = :user WHERE {$where}",
        $bind
    );

    return Json::response($response, [
        'acknowledged' => (int) $acknowledged,
        'until'        => $until,
        'outstanding'  => History::outstandingSecurityCount(),
    ]);
});

/**
 * What happened to one object, scoped exactly as the listing is. It used to
 * answer for any object named, so any token could read every user's history --
 * and a `users` snapshot carries an email address and an admin flag.
 */
$app->get('/v1/history/{object}/{object_id}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Auth::actor($request);

    $page = History::visibleTo($user_id, $isAdmin, [
        'object'    => $args['object'],
        'object_id' => $args['object_id'],
        'limit'     => $request->getQueryParams()['limit'] ?? 500,
    ]);

    return Json::response($response, [
        'history' => $page['rows'],
        'total'   => $page['total'],
    ]);
});
