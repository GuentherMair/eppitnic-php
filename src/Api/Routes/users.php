<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\ClientIp;
use Eppitnic\Api\Json;
use Eppitnic\Api\LoginRateLimit;
use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\User;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/users/renew-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    return Json::response($response, Auth::issueToken((array) $decoded->data));
});

$app->get('/v1/users/me', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    return Json::response($response, (array) $decoded->data);
});

$app->post('/v1/users/authenticate', function (Request $request, Response $response, array $args): Response {
    $params   = $request->getParsedBody() ?? [];
    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    // Before the credentials are looked at, not after: the point of the limit
    // is that guessing costs something, and a check that runs after the guess
    // has been evaluated has already done the work an attacker wanted.
    if (LoginRateLimit::isExceeded()) {
        $retryAfter = LoginRateLimit::retryAfter();

        // recorded, but as its own event -- blocks must not feed the counter
        // that produced them, or a blocked network would extend its own block
        // by continuing to knock
        History::recordSecurityEvent('login_blocked', $request, null, [
            'username' => (string) $username,
        ], 'secread');

        return Json::response($response, [
            'error'       => 'Too many failed login attempts. Try again later.',
            'retry_after' => $retryAfter,
        ], 429)->withHeader('Retry-After', (string) $retryAfter);
    }

    if (empty($username)) {
        return Json::response($response, ['error' => 'Please provide a username'], 401);
    }
    if (empty($password)) {
        return Json::response($response, ['error' => 'Please provide a password'], 401);
    }


    $user = R::getAll("SELECT
        id, admin, username, password, totp_secret, debug,
        max_token_age, max_idle_time
    FROM users WHERE username = :username AND active = 1", [
        ':username' => $username,
    ]);

    if (empty($user) || !password_verify($password, $user[0]['password'])) {
        // The response says only "wrong username or password", so that it
        // cannot be used to find out which usernames exist. The log may be
        // precise -- it is read by an operator, not by whoever is guessing.
        History::recordSecurityEvent('login_failed', $request, empty($user) ? null : (int) $user[0]['id'], [
            'username' => (string) $username,
            'reason'   => empty($user) ? 'no such active user' : 'wrong password',
        ], 'denied');

        return Json::response($response, ['error' => 'Wrong username or password'], 401);
    }

    $hasTotp   = !empty($user[0]['totp_secret']);
    $onSafeNet = false;
    foreach (Config::get('safe_networks') as $cidr) {
        if (ClientIp::inCidr($cidr)) {
            $onSafeNet = true;
            break;
        }
    }
    $needsTotp = $hasTotp && !$onSafeNet;

    if ($needsTotp) {
        $totpCode = $params['totp'] ?? '';
        if (empty($totpCode)) {
            return Json::response($response, ['error' => 'MFA code required'], 401);
        }
        if (!Auth::totpVerify($user[0]['totp_secret'], $totpCode)) {
            // counted like any other failure: the password alone is not a
            // login here, so guessing the second factor has to cost the same
            History::recordSecurityEvent('login_failed', $request, (int) $user[0]['id'], [
                'username' => (string) $username,
                'reason'   => 'wrong MFA code',
            ], 'denied');

            return Json::response($response, ['error' => 'Invalid MFA code'], 401);
        }
    }

    // Recorded like the failures, and with the same care: the token this call
    // is about to issue is a credential, so it is not written here any more
    // than the password was.
    History::recordSecurityEvent('login_succeeded', $request, (int) $user[0]['id'], [
        'username'  => (string) $username,
        'mfa'       => $needsTotp ? 'verified' : ($hasTotp ? 'skipped on a safe network' : 'not configured'),
    ], 'login');

    return Json::response($response, Auth::issueToken([
        'id'            => $user[0]['id'],
        'admin'         => $user[0]['admin'],
        'username'      => $user[0]['username'],
        'has_totp'      => $hasTotp,
        'needs_totp'    => $needsTotp,
        'totp_verified' => $hasTotp,
        'debug'         => (bool) $user[0]['debug'],
        'max_token_age'   => $user[0]['max_token_age'],
        'max_idle_time'   => $user[0]['max_idle_time'],
    ]));
});

$app->get('/v1/users', function (Request $request, Response $response, array $args): Response {
    $actor = Auth::actor($request);

    $users = R::getAll("SELECT " . User::readColumns($actor['isAdmin']) . " FROM users");
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->get('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $actor = Auth::actor($request);

    $users = R::getAll("SELECT " . User::readColumns($actor['isAdmin']) . " FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->put('/v1/changepassword/{id}', function (Request $request, Response $response, array $args): Response {
    $decoded  = Auth::requireMfa($request);
    $user_id   = (int) $decoded->data->id;
    $params   = $request->getParsedBody() ?? [];
    $password = $params['password'] ?? '';

    if (empty($password)) {
        return Json::response($response, ['error' => 'Please provide a password'], 401);
    }

    // 400, not 401: the password was supplied, it is simply not good enough.
    // Before the authorization test, which is about *whose* password this is --
    // a caller changing their own still has to meet the rule
    if ( ! PasswordPolicy::isAcceptable($password)) {
        return Json::response($response, ['error' => PasswordPolicy::explain($password)], 400);
    }

    // 403, not 401: the caller is authenticated, they are just not allowed to
    // change this particular user's password. Matches every other authorization
    // refusal in the codebase.
    if (($args['id'] != $decoded->data->id) && ($decoded->data->admin != 1)) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    R::exec("
        UPDATE users SET
            password     = :password
        WHERE id = :id
    ", [
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':id'       => $args['id']
    ]);

    $users = R::getAll("SELECT
        id, active, admin, username, 'PASSWORD_CHANGED' AS password, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->put('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // load the row first: an unknown id is a 404 rather than a silent no-op,
    // and an omitted field falls back to its current value instead of NULL --
    // as password has always done here
    $current = R::getRow("SELECT * FROM users WHERE id = :id", [':id' => $args['id']]);
    if (empty($current)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    // the UNIQUE column is pre-checked (excluding this row) for the same reason
    // as in POST: a 400 beats an uncaught SQL error
    $username = $params['username'] ?? $current['username'];
    if ($username !== $current['username']) {
        $taken = (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id", [
            ':username' => $username,
            ':id'       => $args['id'],
        ]);
        if ($taken > 0) {
            return Json::response($response, ['error' => "username '{$username}' is already taken"], 400);
        }
    }

    $fields = [
        'description'    => $params['description'] ?? $current['description'],
        'username'       => $username,
        'email'          => $params['email'] ?? $current['email'],
        'max_operations' => (int) ($params['max_operations'] ?? $current['max_operations']),
        'active'         => (int) ($params['active'] ?? $current['active']),
        'admin'          => (int) ($params['admin'] ?? $current['admin']),
        'max_token_age'  => $params['max_token_age'] ?? $current['max_token_age'],
        'max_idle_time'  => $params['max_idle_time'] ?? $current['max_idle_time'],
        'debug'          => (int) ($params['debug'] ?? $current['debug']),
    ];
    // the password column is only touched when a new one was actually supplied
    if ( ! empty($params['password'])) {
        if ( ! PasswordPolicy::isAcceptable($params['password'])) {
            return Json::response($response, ['error' => PasswordPolicy::explain($params['password'])], 400);
        }
        $fields['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
    }

    $set = [];
    $bind = [':id' => $args['id']];
    foreach ($fields as $k => $v) {
        $set[] = "{$k} = :{$k}";
        $bind[":{$k}"] = $v;
    }
    R::exec("UPDATE users SET " . implode(', ', $set) . " WHERE id = :id", $bind);

    $users = R::getAll("SELECT
        id, active, admin, username, email, max_operations,
        max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Validate::requireFields($params, ['username', 'password'])) {
        return Json::response($response, ['error' => $err], 400);
    }

    // pre-check the UNIQUE column, so a collision comes back as a 400 with a
    // clear message instead of an uncaught SQL error surfacing as a 500
    if ( ! PasswordPolicy::isAcceptable($params['password'] ?? '')) {
        return Json::response($response, ['error' => PasswordPolicy::explain((string) ($params['password'] ?? ''))], 400);
    }

    $taken = R::getRow("SELECT username FROM users WHERE username = :username", [
        ':username' => $params['username'],
    ]);
    if ( ! empty($taken)) {
        return Json::response($response, ['error' => "username '{$params['username']}' is already taken"], 400);
    }

    R::exec("
        INSERT INTO users (
            description, username, password, email,
            max_operations, active, admin, max_token_age, max_idle_time, debug
        ) VALUES (
            :description, :username, :password, :email,
            :max_operations, :active, :admin, :max_token_age, :max_idle_time, :debug
        )
    ", [
        ':description'    => $params['description'] ?? null,
        ':username'       => $params['username'],
        ':password'       => password_hash($params['password'], PASSWORD_DEFAULT),
        ':email'          => $params['email'] ?? null,
        // 0 means "no quota" -- see the max_operations check in
        // src/Api/Routes/domain.php
        ':max_operations' => (int) ($params['max_operations'] ?? 0),
        ':active'         => (int) ($params['active'] ?? 1),
        ':admin'          => (int) ($params['admin'] ?? 0),
        ':max_token_age'  => $params['max_token_age'] ?? null,
        ':max_idle_time'  => $params['max_idle_time'] ?? null,
        ':debug'          => (int) ($params['debug'] ?? 0),
    ]);

    $id = R::getInsertID();
    $users = R::getAll("SELECT
        id, active, admin, username, email, max_operations,
        max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $id,
    ]);
    History::record('users', $id, 'create', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ], 201);
});

$app->delete('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);

    R::exec("UPDATE users SET active = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int)$args['id'], 'delete', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $user = R::getAll("SELECT id, username FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    $totp = Auth::totpGenerate($user[0]['username']);

    R::exec("UPDATE users SET totp_secret_pending = :secret WHERE id = :id", [
        ':secret' => $totp['secret'],
        ':id'     => $args['id'],
    ]);

    return Json::response($response, [
        'secret' => $totp['secret'],
        'uri'    => $totp['uri'],
    ]);
});

$app->put('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $params   = $request->getParsedBody() ?? [];
    $totpCode = $params['totp'] ?? '';

    $user = R::getAll("SELECT id, totp_secret_pending FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if (empty($user[0]['totp_secret_pending'])) {
        return Json::response($response, ['error' => 'No pending TOTP setup found'], 400);
    }
    if (empty($totpCode) || !Auth::totpVerify($user[0]['totp_secret_pending'], $totpCode)) {
        return Json::response($response, ['error' => 'Invalid TOTP code'], 401);
    }

    R::exec("UPDATE users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    $users  = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->delete('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::requireMfa($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    R::exec("UPDATE users SET totp_secret = NULL, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    $users  = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $user = R::getAll("SELECT id FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    $params = $request->getParsedBody() ?? [];
    // absolute unix timestamp; 0 = no expiry (infinite)
    $expires = isset($params['expires']) ? (int) $params['expires'] : 0;

    // 32 random bytes as an opaque hex token -- stored only as a hash, same as
    // passwords
    $token = PasswordGenerator::token();
    R::exec("UPDATE users SET api_token = :token, api_token_expires = :expires WHERE id = :id", [
        ':token'   => hash('sha256', $token),
        ':expires' => $expires,
        ':id'      => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    History::record('users', (int) $args['id'], 'update', ['api_token_expires' => $expires], $user_id);

    // the plaintext token is only ever shown here, at issue time -- it can't be
    // recovered later since only its hash is stored
    return Json::response($response, [
        'token'   => $token,
        'expires' => $expires,
    ]);
});

$app->delete('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    R::exec("UPDATE users SET api_token = NULL, api_token_expires = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    History::record('users', (int) $args['id'], 'update', ['api_token' => null], $user_id);

    return Json::response($response, ['revoked' => true]);
});
