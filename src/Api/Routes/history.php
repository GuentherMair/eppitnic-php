<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

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
