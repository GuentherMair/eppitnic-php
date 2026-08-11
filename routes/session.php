<?php

use Net\EPP\Client;
use Net\EPP\Config;
use Net\EPP\Helpers;
use Net\EPP\IT\Session;
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

    return Helpers::json($response, ['epp' => $public]);
});

$app->get('/v1/session/credit', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtVerify($request);

    try {
        $credit = Helpers::withEppSession(function ($nic, $session) {
            return $session->showCredit();
        });
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
    // 16 hex characters from the CSPRNG -- identical to what
    // Helpers::rotateEppPasswordOnReminder() generates for the same credential.
    // This used to be substr(md5(rand()), 0, 8): eight characters carrying at
    // most rand()'s ~31 bits, protecting the shared registry account. Note 16
    // is also the ceiling -- EPP's pwType caps this credential at 16
    // characters, and the registry rejects anything longer outright.
    $newPassword = $params['password'] ?? bin2hex(random_bytes(8));

    // this is the shared EPP registry credential, not a per-user login password
    // (that's PUT /v1/changepassword/{id}) -- can't go through Helpers::withEppSession()
    // here since its normal login() would already run before we get a chance
    // to make *our* login the one that changes the password
    $nic = new Client();
    $session = new Session($nic);

    if ( ! $session->hello()) {
        return Helpers::json($response, ['error' => 'EPP session unavailable: connection failed'], 502);
    }
    if ($session->login($newPassword) === FALSE) {
        return Helpers::json($response, ['error' => $session->getError()], 400);
    }
    $session->logout();

    // persist locally -- the registry password just changed, the 'epp' setting must follow
    try {
        $epp = Config::get('epp');
        $epp['password'] = $newPassword;
        Config::set('epp', $epp);
    } catch (\Throwable $e) {
        return Helpers::json($response, ['error' => 'registry password changed but could not persist to settings: ' . $e->getMessage() . ' -- update it manually'], 500);
    }

    return Helpers::json($response, ['changed' => true]);
});
