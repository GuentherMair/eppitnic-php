<?php

use Net\EPP\Config;
use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * the `epp` setting's fields that may be exposed over the API.
 *
 * An allow-list, not a blacklist of secrets: the shared registry password lives
 * in the same setting, and so might whatever secret gets added next. Listing
 * what is safe means a new field defaults to *not* being published, rather than
 * leaking until somebody remembers to exclude it. Add new non-secret fields
 * here deliberately.
 */
const EPP_PUBLIC_FIELDS = [
    'server', 'server_deleted', 'port', 'interface',
    'username', 'lang', 'cl_trid_prefix', 'lastPasswordUpdate',
];

$app->get('/v1/session/epp', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);

    $epp = Config::get('epp');

    $public = [];
    foreach (EPP_PUBLIC_FIELDS as $field) {
        $public[$field] = $epp[$field] ?? null;
    }
    // never the password itself -- this only reports whether one is configured,
    // which is what a UI needs to tell "not set up yet" from "set up"
    $public['password_set'] = ($epp['password'] ?? '') !== '';

    // a rotation that did not finish: the registry may be holding a credential
    // this installation has not adopted. `eppitnic doctor epp-password` settles
    // it; the candidate itself stays out of the response.
    $public['rotation_pending'] = ($epp['pendingPassword'] ?? '') !== '';

    return Helpers::json($response, ['epp' => $public]);
});

$app->get('/v1/session/credit', function (Request $request, Response $response, array $args): Response {
    ['debug' => $debug] = Helpers::actor($request);

    try {
        $credit = Helpers::withEppSession(function ($nic, $session) {
            return $session->showCredit();
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    return Helpers::json($response, ['credit' => $credit]);
});

$app->get('/v1/poll-queue', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $params = $request->getQueryParams();
    $activeOnly = ($params['active'] ?? '1') !== '0';

    $where = $activeOnly ? 'archived_time IS NULL' : '1 = 1';
    $messages = R::getAll("SELECT * FROM messages WHERE {$where} ORDER BY id DESC");

    return Helpers::json($response, ['messages' => $messages]);
});

$app->get('/v1/poll-queue/{id}', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $id = (int) $args['id'];

    $message = R::getRow("SELECT * FROM messages WHERE id = ?", [$id]);
    if (empty($message)) {
        return Helpers::json($response, ['error' => "Message id {$id} not found"], 404);
    }

    return Helpers::json($response, ['message' => $message]);
});

$app->post('/v1/poll-queue/{id}/archive', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $id = (int) $args['id'];

    R::exec("UPDATE messages SET archived_time = NOW(), archived_user_id = ? WHERE id = ?", [$user_id, $id]);

    return Helpers::json($response, ['archived' => true, 'id' => $id]);
});

$app->post('/v1/session/change-password', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // this is the shared EPP registry credential, not a per-user login password
    // (that's PUT /v1/changepassword/{id}). Helpers::changeEppPassword() owns
    // the ordering that makes an interrupted change recoverable, and generates
    // the password when the caller does not supply one.
    $outcome = Helpers::changeEppPassword($params['password'] ?? null);

    if ( ! $outcome['ok']) {
        $status = match ($outcome['stage']) {
            'connect'  => 502,
            'registry' => 400,
            default    => 500,
        };
        return Helpers::json($response, ['error' => $outcome['error']], $status);
    }

    return Helpers::json($response, ['changed' => true]);
});
