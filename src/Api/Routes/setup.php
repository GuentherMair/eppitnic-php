<?php

use Eppitnic\Api\Json;
use Eppitnic\Setup\DatabaseCredentials;
use Eppitnic\Setup\Installer;
use Eppitnic\Support\PasswordPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Unauthenticated by design -- there is no admin token yet -- so
// Installer::isOpen() is the gate instead, and each route 404s once setup is
// closed. Through isOpen(), not ConfigFile::exists(), to keep one predicate.

$app->get('/v1/setup', function (Request $request, Response $response): Response {
    if ( ! Installer::isOpen()) {
        return Json::response($response, ['error' => 'Not found'], 404);
    }
    return Json::response($response, [
        'required' => true,
        'fields'   => Installer::requirements(),
        // so the form can state the rule before anybody types into it, from
        // the same list install() will judge the answer against
        'password_policy' => PasswordPolicy::describe(),
    ]);
});

$app->post('/v1/setup/verify', function (Request $request, Response $response): Response {
    if ( ! Installer::isOpen()) {
        return Json::response($response, ['error' => 'Not found'], 404);
    }

    $body = (array) $request->getParsedBody();
    try {
        $result = Installer::verify(DatabaseCredentials::fromArray($body));
    } catch (\Throwable $e) {
        return Json::response($response, ['error' => setupSafeError($e)], 400);
    }

    return Json::response($response, ['ok' => true] + $result);
});

$app->post('/v1/setup', function (Request $request, Response $response): Response {
    if ( ! Installer::isOpen()) {
        return Json::response($response, ['error' => 'Not found'], 404);
    }

    $body = (array) $request->getParsedBody();
    try {
        $result = Installer::install($body);
    } catch (\InvalidArgumentException $e) {
        // The caller's input is wrong. Answered, not logged: a stack trace per
        // typo would bury the failures worth reading, and the message is safe
        // to return because this code raised it, not a driver
        return Json::response($response, ['error' => $e->getMessage()], 400);
    } catch (\Throwable $e) {
        // Anything else is the installation failing rather than the request
        // being wrong. The full detail, including any raw SQL a schema failure
        // carries, goes to the log; only a scrubbed version reaches the body.
        error_log('eppitnic setup failed: ' . $e);
        return Json::response($response, ['error' => setupSafeError($e)], 400);
    }

    return Json::response($response, ['ok' => true] + $result);
});

/**
 * An exception message safe for an HTTP response body: Config::runSqlFile()
 * puts 200 characters of SQL ahead of a ' -- ' marker, so this truncates there
 * and caps length. Validation messages have none and pass through unchanged.
 */
function setupSafeError(\Throwable $e): string {
    $message = $e->getMessage();
    $cut = strpos($message, ' -- ');
    if ($cut !== false) {
        $message = substr($message, 0, $cut) . ' (see the server log for details)';
    }
    return substr($message, 0, 300);
}
