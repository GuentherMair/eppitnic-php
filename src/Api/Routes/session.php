<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Service\EppSession;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/session/epp', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $epp = Config::get('epp');

    // Config::EPP_PUBLIC_FIELDS is the allow-list, shared with `eppitnic
    // config show` so that a field added to `epp` is hidden from both by
    // default rather than from whichever one was remembered.
    $public = [];
    foreach (Config::EPP_PUBLIC_FIELDS as $field) {
        $public[$field] = $epp[$field] ?? null;
    }
    // never the password itself, only whether one is configured -- what a UI
    // needs to tell "not set up" from "set up". The credential has its own
    // endpoint, so reading it is deliberate
    $public['password_set'] = ($epp['password'] ?? '') !== '';

    // a rotation that did not finish: the registry may be holding a credential
    // this installation has not adopted. `eppitnic doctor epp-password` settles
    // it; the candidate itself stays out of the response.
    $public['rotation_pending'] = ($epp['pendingPassword'] ?? '') !== '';

    return Json::response($response, ['epp' => $public]);
});

/**
 * The registry credential itself, as its own request rather than part of GET
 * /v1/session/epp: one arriving while a settings screen renders ends up in
 * caches and screenshots. Admin only, and refused on an incomplete TOTP
 * session.
 */
$app->get('/v1/session/epp/credentials', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);

    $epp = Config::get('epp');
    if (($epp['password'] ?? '') === '') {
        return Json::response($response, ['error' => 'No registry password is configured'], 404);
    }

    $credentials = [
        'server'   => $epp['server'] ?? null,
        'username' => $epp['username'] ?? null,
        'password' => $epp['password'],
    ];

    // An unfinished rotation means the registry holds one of two passwords,
    // and only it knows which (`doctor epp-password` asks). Withholding the
    // candidate would lock the operator out exactly when they need it
    if (($epp['pendingPassword'] ?? '') !== '') {
        $credentials['pending_password'] = $epp['pendingPassword'];
        $credentials['note'] = 'A rotation did not finish: the registry holds either password. '
                             . 'Run `eppitnic doctor epp-password` to settle it.';
    }

    // A credential is leaving, so who took it and from where is recorded first.
    // Not the password: the log is read by more people, and more casually, than
    // the thing it is about
    History::recordSecurityEvent('epp_credentials_retrieved', $request, $user_id, [
        'rotation_pending' => isset($credentials['pending_password']),
    ]);

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

    // The whole queue runs to ~3 MB, so a screen that wants only the latest few
    // asks for them; `total` is how many matched, whatever was returned.
    $limit = isset($params['limit']) ? min(500, max(1, (int) $params['limit'])) : null;
    $messages = R::getAll("SELECT * FROM messages WHERE {$where} ORDER BY id DESC" . ($limit !== null ? " LIMIT {$limit}" : ''));
    $total = (int) R::getCell("SELECT COUNT(*) FROM messages WHERE {$where}");

    return Json::response($response, ['messages' => $messages, 'total' => $total]);
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

    return Json::response($response, [
        'archived'      => true,
        'id'            => $id,
        'archived_time' => R::getCell("SELECT archived_time FROM messages WHERE id = ?", [$id]),
    ]);
});

/**
 * Archive every unarchived message up to a moment the caller names, in one go.
 * The moment is the newest message the caller has looked at, so whatever the
 * registry delivered after they loaded the list stays in the queue instead of
 * being archived unread. Same contract as POST /v1/history/acknowledge.
 *
 * Body: {"until": "YYYY-MM-DD HH:MM:SS"}, compared to created_time inclusively.
 * `created_time` is only second-resolution, so a burst can land several
 * messages in the same second as the one the caller saw; pass
 * {"until_id": N} (that message's id) for an exact cutoff instead -- ids are
 * monotonic.
 */
$app->post('/v1/poll-queue/archive', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];
    $until = (string) ($body['until'] ?? '');

    if ( ! Validate::isDatetime($until)) {
        return Json::response($response, ['error' => "until must be a datetime such as '2026-09-21 14:41:36'"], 400);
    }

    $untilId = $body['until_id'] ?? null;
    if ($untilId !== null && filter_var($untilId, FILTER_VALIDATE_INT) === false) {
        return Json::response($response, ['error' => 'until_id must be an integer'], 400);
    }

    if ($untilId !== null) {
        $where = 'archived_time IS NULL AND id <= :until_id';
        $bind = [':user' => $user_id, ':until_id' => (int) $untilId];
    } else {
        $where = 'archived_time IS NULL AND created_time <= :until';
        $bind = [':user' => $user_id, ':until' => $until];
    }

    $archived = R::exec(
        "UPDATE messages SET archived_time = CURRENT_TIMESTAMP, archived_user_id = :user WHERE {$where}",
        $bind
    );

    return Json::response($response, array_filter([
        'archived'    => (int) $archived,
        'until'       => $until,
        'until_id'    => $untilId !== null ? (int) $untilId : null,
        'outstanding' => (int) R::getCell('SELECT COUNT(*) FROM messages WHERE archived_time IS NULL'),
    ], static fn($v) => $v !== null));
});

$app->post('/v1/session/change-password', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // A supplied password must satisfy epp:pwType first, as `config
    // epp-password` requires: apply() writes the candidate before sending, so a
    // doomed one still costs a write. An omitted one is generated and is fine
    if (($params['password'] ?? '') !== '') {
        if ($error = Validate::eppField('password', (string) $params['password'])) {
            return Json::response($response, ['error' => $error], 400);
        }
    }

    // the shared registry credential, not a per-user password. Stamping the
    // attempt is not optional: `poll process` reads the same
    // `lastPasswordUpdate`, and would rotate right after a manual change
    $outcome = RegistryPasswordChange::apply($params['password'] ?? null, true);

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
