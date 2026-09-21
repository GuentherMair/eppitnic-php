<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Service\UserSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// A user's defaults and NS sets are theirs to read and change; an admin may
// act for anyone (Auth::actorFor).

// Every write answers with the whole current state, so a client replaces what
// it holds instead of merging. $recorded is what the history row says changed.
$userSettingsReply = static function (Response $response, array $result, int $userId, int $actorId, array $recorded, int $status = 200): Response {
    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    History::record('users', $userId, 'update', $recorded, $actorId);
    return Json::response($response, ['settings' => $result['settings']], $status);
};

$app->get('/v1/users/{id}/settings', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    Auth::actorFor($request, $userId);

    $settings = UserSettings::load($userId);
    if ($settings === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    return Json::response($response, ['settings' => $settings]);
});

$app->put('/v1/users/{id}/settings', function (Request $request, Response $response, array $args) use ($userSettingsReply): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $params = $request->getParsedBody() ?? [];
    $result = UserSettings::saveDefaults($userId, $params);

    return $userSettingsReply(
        $response, $result, $userId, $actor['id'],
        array_intersect_key($params, array_flip(['countrycode', 'techc', 'dnsset'])),
    );
});

$app->post('/v1/users/{id}/nssets', function (Request $request, Response $response, array $args) use ($userSettingsReply): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $params = $request->getParsedBody() ?? [];
    $result = UserSettings::addSet($userId, $params);

    return $userSettingsReply(
        $response, $result, $userId, $actor['id'],
        ['nsset_added' => (string) ($params['name'] ?? '')], 201,
    );
});

$app->put('/v1/users/{id}/nssets/{name}', function (Request $request, Response $response, array $args) use ($userSettingsReply): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $result = UserSettings::replaceSet($userId, $args['name'], $request->getParsedBody() ?? []);

    return $userSettingsReply($response, $result, $userId, $actor['id'], ['nsset_changed' => $args['name']]);
});

$app->delete('/v1/users/{id}/nssets/{name}', function (Request $request, Response $response, array $args) use ($userSettingsReply): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $result = UserSettings::removeSet($userId, $args['name']);

    return $userSettingsReply($response, $result, $userId, $actor['id'], ['nsset_removed' => $args['name']]);
});
