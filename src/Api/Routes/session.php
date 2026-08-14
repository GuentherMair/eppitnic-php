<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Service\EppSession;
use Eppitnic\Service\RegistryPasswordChange;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/session/epp', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    /**
     * the `epp` setting's fields that may be exposed over the API.
     *
     * An allow-list, not a blacklist of secrets: the shared registry password
     * lives in the same setting, and so might whatever secret gets added next.
     * Listing what is safe means a new field defaults to *not* being published,
     * rather than leaking until somebody remembers to exclude it. Add new
     * non-secret fields here deliberately.
     *
     * Scoped to this closure rather than a file-level const: route files are
     * `require`d, not `require_once`d, and redeclaring a constant is a warning
     * today and an error in PHP 9.
     */
    $publicFields = [
        'server', 'server_deleted', 'port', 'interface',
        'username', 'lang', 'cl_trid_prefix', 'lastPasswordUpdate',
    ];

    $epp = Config::get('epp');

    $public = [];
    foreach ($publicFields as $field) {
        $public[$field] = $epp[$field] ?? null;
    }
    // never the password itself -- this only reports whether one is configured,
    // which is what a UI needs to tell "not set up yet" from "set up".
    // GET /v1/session/epp/credentials returns the credential itself, as its
    // own request, so that reading it is a deliberate act rather than a
    // side effect of loading a settings screen.
    $public['password_set'] = ($epp['password'] ?? '') !== '';

    // a rotation that did not finish: the registry may be holding a credential
    // this installation has not adopted. `eppitnic doctor epp-password` settles
    // it; the candidate itself stays out of the response.
    $public['rotation_pending'] = ($epp['pendingPassword'] ?? '') !== '';

    return Json::response($response, ['epp' => $public]);
});

/**
 * The registry credential itself, for the operator who has to use it elsewhere
 * -- nic.it's own web interface, a second tool, a support call.
 *
 * Deliberately not part of GET /v1/session/epp. That endpoint is what a
 * settings screen loads, and a credential that arrives as a side effect of
 * rendering a page ends up in browser caches, proxy logs and screenshots
 * belonging to people who never asked for it. Here it takes its own request.
 *
 * This exists because the password is rotated automatically: after
 * `eppitnic poll process` acts on a passwdReminder, nobody knows the current
 * credential, and reading it out of the settings table by hand is the only
 * alternative to this.
 *
 * Admin only, and Auth::requireAdmin() additionally refuses a token whose
 * owner has TOTP enabled but has not completed it for this session.
 */
$app->get('/v1/session/epp/credentials', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $epp = Config::get('epp');
    if (($epp['password'] ?? '') === '') {
        return Json::response($response, ['error' => 'No registry password is configured'], 404);
    }

    $credentials = [
        'server'   => $epp['server'] ?? null,
        'username' => $epp['username'] ?? null,
        'password' => $epp['password'],
    ];

    // An unfinished rotation means the registry may hold either of two
    // passwords, and which one is a question only the registry can answer
    // (`eppitnic doctor epp-password` asks it). Withholding the candidate here
    // would leave the operator unable to get in at all in exactly the case
    // where they most need to.
    if (($epp['pendingPassword'] ?? '') !== '') {
        $credentials['pending_password'] = $epp['pendingPassword'];
        $credentials['note'] = 'A rotation did not finish: the registry holds either password. '
                             . 'Run `eppitnic doctor epp-password` to settle it.';
    }

    // Deliberately not written to the changelog: its `object` enum covers
    // users, contacts and domains, and a credential disclosure is none of
    // those. Recording it against the reading admin as an 'update' would put
    // a false entry in the audit trail, which is worse than no entry. Adding
    // 'settings' to that enum would make this auditable properly.
    return Json::response($response, ['credentials' => $credentials]);
});

$app->get('/v1/session/credit', function (Request $request, Response $response, array $args): Response {
    ['debug' => $debug] = Auth::actor($request);

    try {
        $credit = EppSession::run(function ($nic, $session) {
            return $session->showCredit();
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    return Json::response($response, ['credit' => $credit]);
});

$app->get('/v1/poll-queue', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    $params = $request->getQueryParams();
    $activeOnly = ($params['active'] ?? '1') !== '0';

    $where = $activeOnly ? 'archived_time IS NULL' : '1 = 1';
    $messages = R::getAll("SELECT * FROM messages WHERE {$where} ORDER BY id DESC");

    return Json::response($response, ['messages' => $messages]);
});

$app->get('/v1/poll-queue/{id}', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    $id = (int) $args['id'];

    $message = R::getRow("SELECT * FROM messages WHERE id = ?", [$id]);
    if (empty($message)) {
        return Json::response($response, ['error' => "Message id {$id} not found"], 404);
    }

    return Json::response($response, ['message' => $message]);
});

$app->post('/v1/poll-queue/{id}/archive', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $id = (int) $args['id'];

    R::exec("UPDATE messages SET archived_time = NOW(), archived_user_id = ? WHERE id = ?", [$user_id, $id]);

    return Json::response($response, ['archived' => true, 'id' => $id]);
});

$app->post('/v1/session/change-password', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // this is the shared EPP registry credential, not a per-user login password
    // (that's PUT /v1/changepassword/{id}). RegistryPasswordChange::apply() owns
    // the ordering that makes an interrupted change recoverable, and generates
    // the password when the caller does not supply one.
    $outcome = RegistryPasswordChange::apply($params['password'] ?? null);

    if ( ! $outcome['ok']) {
        $status = match ($outcome['stage']) {
            'connect'  => 502,
            'registry' => 400,
            default    => 500,
        };
        return Json::response($response, ['error' => $outcome['error']], $status);
    }

    return Json::response($response, ['changed' => true]);
});
