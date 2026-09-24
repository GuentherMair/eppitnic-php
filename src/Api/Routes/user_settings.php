<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Service\Notifier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A user's own filter on email notifications (Notifier) -- theirs to read
 * and change, or their manager's or an admin's to reach into
 * (Auth::actorFor). Only takes effect while the system-wide
 * `smtp.recipient_mode` includes 'user'; see GET/PATCH /v1/smtp.
 */
$app->get('/v1/users/{id}/notifications', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    Auth::actorFor($request, $userId);

    $prefs = Notifier::loadUserPreferences($userId);
    if ($prefs === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    return Json::response($response, ['notifications' => $prefs, 'message_types' => Notifier::MESSAGE_TYPES]);
});

$app->patch('/v1/users/{id}/notifications', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $params = $request->getParsedBody() ?? [];
    $result = Notifier::saveUserPreferences($userId, $params);
    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    History::record('users', $userId, 'update', array_intersect_key($params, array_flip(['enabled', 'message_types', 'fulltext'])), $actor['id']);
    return Json::response($response, ['notifications' => $result['settings']]);
});
