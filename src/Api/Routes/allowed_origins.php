<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\AllowedOrigins;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `allowed_origins` setting -- see AllowedOrigins, shared with
 * `config allowed-origins`. The CORS middleware checks a write against the
 * list stored before it, so removing the caller's own origin still succeeds.
 */
$app->get('/v1/allowed-origins', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, ['allowed_origins' => AllowedOrigins::get()]);
});

$app->put('/v1/allowed-origins', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    if ( ! is_array($body['allowed_origins'] ?? null)) {
        return Json::response($response, ['error' => 'allowed_origins must be a list of origins'], 400);
    }

    try {
        $stored = AllowedOrigins::set($body['allowed_origins'], $userId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['allowed_origins' => $stored]);
});
