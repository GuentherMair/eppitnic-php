<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Support\About;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Identity, licence and runtime dependencies -- see Support\About. */
$app->get('/v1/about', function (Request $request, Response $response, array $args): Response {
    Auth::actor($request);

    return Json::response($response, About::info());
});
