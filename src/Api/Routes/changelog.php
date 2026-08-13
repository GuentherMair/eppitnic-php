<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/changelog/{object}/{object_id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::userId($request);

    $changelog = R::getAll("SELECT * FROM changelog WHERE object = :object AND object_id = :object_id ORDER BY timestamp DESC", [
        ':object'    => $args['object'],
        ':object_id' => $args['object_id'],
    ]);
    return Json::response($response, [
        'changelog' => $changelog,
    ]);
});
