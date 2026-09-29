<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\DnssecSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `dnssec` setting -- see DnssecSettings, shared with `config dnssec`.
 */
$app->get('/v1/dnssec', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, ['dnssec' => ['active' => DnssecSettings::active()]]);
});

$app->patch('/v1/dnssec', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    if (array_keys($body) !== ['active'] || ! is_bool($body['active'])) {
        return Json::response($response, ['error' => 'give {"active": true|false}'], 400);
    }

    if (DnssecSettings::active() !== $body['active']) {
        DnssecSettings::set($body['active'], $userId);
    }

    return Json::response($response, ['dnssec' => ['active' => DnssecSettings::active()]]);
});
