<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Service\RemoteAuthSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `remote_auth` setting -- see RemoteAuthSettings, shared with
 * `config remote-auth-set`. `trusted_proxies` is returned read-only: header
 * mode only works from those addresses, so an editor can warn about it.
 */
$app->get('/v1/remote-auth', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, [
        'remote_auth'     => RemoteAuthSettings::get(),
        'trusted_proxies' => array_values((array) Config::get('trusted_proxies')),
    ]);
});

$app->patch('/v1/remote-auth', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);

    try {
        RemoteAuthSettings::set($request->getParsedBody() ?? [], $userId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, [
        'remote_auth'     => RemoteAuthSettings::get(),
        'trusted_proxies' => array_values((array) Config::get('trusted_proxies')),
    ]);
});
