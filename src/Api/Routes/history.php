<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The security log, newest first.
 *
 * Admin only, and separate from the per-object lookup below because reviewing
 * security events is a different question: not "what happened to this domain"
 * but "what has happened that nobody has looked at yet". A failed login at a
 * username that does not exist has no object to be looked up under, so without
 * this it would be reachable only by knowing to ask for object_id 0.
 *
 * `?acknowledged=0` is the working view -- what is still outstanding. Omit it
 * to see everything.
 */
$app->get('/v1/history/security', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $params = $request->getQueryParams();
    $limit  = min(500, max(1, (int) ($params['limit'] ?? 100)));

    $where = "object = 'security'";
    if (isset($params['acknowledged'])) {
        $where .= ($params['acknowledged'] === '0')
            ? ' AND acknowledged_time IS NULL'
            : ' AND acknowledged_time IS NOT NULL';
    }

    $events = R::getAll("SELECT * FROM history WHERE {$where} ORDER BY id DESC LIMIT {$limit}");

    return Json::response($response, [
        'history'      => $events,
        'outstanding'  => (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'security' AND acknowledged_time IS NULL"),
    ]);
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
 * What happened to one object, newest first.
 *
 * `security` is admin-only. The other object types record what someone did to
 * a domain, contact or user; `security` records who read a credential, from
 * which address, with which headers, and that is not something every holder of
 * a valid token should be able to page through.
 *
 * The remaining three are deliberately left unscoped, as they always were --
 * any valid token can read any domain's or contact's history. That is a
 * separate question from this one and is noted in docs/API.md.
 */
$app->get('/v1/history/{object}/{object_id}', function (Request $request, Response $response, array $args): Response {
    if ($args['object'] === 'security') {
        Auth::requireAdmin($request);
    } else {
        Auth::userId($request);
    }

    $history = R::getAll(
        "SELECT * FROM history WHERE object = :object AND object_id = :object_id ORDER BY timestamp DESC",
        [
            ':object'    => $args['object'],
            ':object_id' => $args['object_id'],
        ]
    );

    return Json::response($response, [
        'history' => $history,
    ]);
});
