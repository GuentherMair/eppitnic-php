<?php

use Eppitnic\Api\Json;
use Eppitnic\Setup\DatabaseCredentials;
use Eppitnic\Setup\Installer;
use Eppitnic\Support\PasswordPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Every route here is unauthenticated by design -- there is no admin token to
// check yet, and Installer::isOpen() (currently just "config/config.php
// doesn't exist") is the gate instead. Each one 404s once setup is no longer
// open, so these three routes are unreachable once the application is
// actually configured -- see src/Api/SetupApp.php, the only place that loads
// this file. Checked here through isOpen() rather than ConfigFile::exists()
// directly, so that predicate stays the one place to change if this is ever
// bounded by something more than the file's absence.

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
        // What the caller sent is wrong -- a missing field, a password that
        // does not meet the policy, a mistyped confirmation. Answered, not
        // logged: a stack trace per typo would bury the failures that are
        // actually worth reading, and the message is already safe to return
        // because this code raised it rather than a driver.
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
 * An exception message safe to put in an HTTP response body.
 *
 * Config::runSqlFile()'s failure message embeds up to 200 raw characters of
 * migration SQL ahead of a ' -- ' marker and the driver's own error text --
 * fine on a terminal, not fine in a response body a browser-based installer
 * might display. Truncates there, and caps length regardless, since no
 * legitimate validation message here is anywhere near this long.
 *
 * Validation messages (DatabaseCredentials's "Missing required database
 * field(s): ...", Installer's "admin_username and admin_password are
 * required.", a PDO connect failure's "Unable to connect to the database:
 * ...") contain no ' -- ' and pass through unchanged.
 */
function setupSafeError(\Throwable $e): string {
    $message = $e->getMessage();
    $cut = strpos($message, ' -- ');
    if ($cut !== false) {
        $message = substr($message, 0, $cut) . ' (see the server log for details)';
    }
    return substr($message, 0, 300);
}
