<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Service\Notifier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The system-wide `smtp` setting -- see Notifier, the same class
 * `config smtp-set` uses, so a change made here or on the command line is
 * validated and audited identically (`history`, `object='smtp'`).
 */
$app->get('/v1/smtp', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $smtp = Notifier::get();
    $smtp['password_set'] = ($smtp['password'] ?? '') !== '';
    unset($smtp['password']);

    return Json::response($response, ['smtp' => $smtp, 'message_types' => Notifier::MESSAGE_TYPES]);
});

$app->patch('/v1/smtp', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    try {
        Notifier::set($body, $userId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    $smtp = Notifier::get();
    $smtp['password_set'] = ($smtp['password'] ?? '') !== '';
    unset($smtp['password']);

    return Json::response($response, ['smtp' => $smtp]);
});

/**
 * A live connectivity check over whatever is in the body right now
 * (merged over what is already saved, so a blank password reuses the
 * stored one) -- nothing here is persisted. Works regardless of
 * `smtp.enabled`, so a configuration can be tried before it is turned on.
 */
$app->post('/v1/smtp/test', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    try {
        $result = Notifier::sendTest($body);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 502);
    }
    return Json::response($response, ['sent' => true]);
});
