<?php

use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/changelog/{object}/{object_id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtUserID($request);

    $changelog = R::getAll("SELECT * FROM changelog WHERE object = :object AND object_id = :object_id ORDER BY timestamp DESC", [
        ':object'    => $args['object'],
        ':object_id' => $args['object_id'],
    ]);
    $response->getBody()->write(json_encode([
        'changelog' => $changelog,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
