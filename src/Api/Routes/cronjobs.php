<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\CronjobSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The scheduled jobs' settings -- see CronjobSettings, the same class
 * every `config *-set` CLI command uses, so a change made here or on the
 * command line is validated and audited identically.
 */
$app->get('/v1/cronjobs', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $jobs = [];
    foreach (CronjobSettings::jobs() as $job) {
        $jobs[$job] = CronjobSettings::get($job);
    }

    return Json::response($response, ['jobs' => $jobs]);
});

$app->patch('/v1/cronjobs/{job}', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $job = $args['job'];
    $body = $request->getParsedBody() ?? [];
    $force = (bool) ($body['force'] ?? false);
    unset($body['force']);

    try {
        $settings = CronjobSettings::set($job, $body, $user_id, $force);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['job' => $job, 'settings' => $settings]);
});
