<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail, newest first, filtered by whatever the caller asks for.
 *
 * What comes back is scoped to what the caller may see -- an admin sees
 * everything, everyone else sees the history of the objects they own. See
 * History::visibleTo(). Filters narrow that; they never widen it, so asking
 * for `object=security` as a non-admin returns nothing rather than 403: the
 * answer to "what security events are there" is, for them, none.
 *
 * Filters: object, object_id, action, network, acknowledged (0/1), since,
 * until, limit (max 500), offset.
 *
 * `outstanding` accompanies an admin's response: how many `security` entries
 * nobody has acknowledged, so a UI can badge them without a second request.
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
 * Mark one entry as reviewed.
 *
 * Records who and when rather than setting a flag: an entry that was dismissed
 * is worth being able to ask about later, and "somebody decided this was fine"
 * is not an answer.
 *
 * Acknowledging is not undoing -- the entry stays exactly as it was, and this
 * only says it has been read. Re-acknowledging an entry re-stamps it, which is
 * the honest thing: the last person to look at it is the one on record.
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
 * What happened to one object -- the shorthand for the two filters people ask
 * for together most often.
 *
 * Scoped exactly as the listing is. It used to answer for any object anybody
 * named, which meant any valid token could read every user's history, and a
 * `users` snapshot carries an email address and an admin flag.
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
