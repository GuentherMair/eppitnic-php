<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\RegionSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `region` setting -- see RegionSettings, shared with `config
 * region-set`. `timezones` lists every accepted zone, for a picker.
 */
$app->get('/v1/region', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, [
        'region'    => RegionSettings::get(),
        'timezones' => RegionSettings::timezones(),
    ]);
});

$app->patch('/v1/region', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);

    try {
        $region = RegionSettings::set($request->getParsedBody() ?? [], $userId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['region' => $region]);
});
