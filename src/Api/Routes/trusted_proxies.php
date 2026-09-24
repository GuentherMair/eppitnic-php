<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\TrustedProxies;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `trusted_proxies` setting -- see TrustedProxies, shared with
 * `config trusted-proxies`. `peer` is the address this very request came
 * from: behind a proxy, that is the address the proxy needs to be listed as.
 */
$app->get('/v1/trusted-proxies', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, [
        'trusted_proxies' => TrustedProxies::get(),
        'peer'            => $request->getServerParams()['REMOTE_ADDR'] ?? null,
    ]);
});

$app->put('/v1/trusted-proxies', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    if ( ! is_array($body['trusted_proxies'] ?? null)) {
        return Json::response($response, ['error' => 'trusted_proxies must be a list of networks'], 400);
    }

    try {
        $stored = TrustedProxies::set($body['trusted_proxies'], $userId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, [
        'trusted_proxies' => $stored,
        'peer'            => $request->getServerParams()['REMOTE_ADDR'] ?? null,
    ]);
});
