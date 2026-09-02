<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail, newest first, scoped by History::visibleTo(); filters narrow
 * and never widen, so `object=security` as a non-admin returns nothing, not 403.
 * Admins also get `outstanding`, for badging.
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
