<?php

use Net\EPP\Config;
use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/users/renew-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    return Helpers::json($response, Helpers::jwtBuild((array) $decoded->data));
});

$app->get('/v1/users/me', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    return Helpers::json($response, (array) $decoded->data);
});

$app->post('/v1/users/authenticate', function (Request $request, Response $response, array $args): Response {
    $params   = $request->getParsedBody() ?? [];
    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    if (empty($username)) {
        return Helpers::json($response, ['error' => 'Please provide a username'], 401);
    }
    if (empty($password)) {
        return Helpers::json($response, ['error' => 'Please provide a password'], 401);
    }

    $user = R::getAll("SELECT
        id, admin, username, password, totp_secret, debug,
        max_token_age, max_idle_time
    FROM users WHERE username = :username AND active = 1", [
        ':username' => $username,
    ]);

    if (empty($user) || !password_verify($password, $user[0]['password'])) {
        return Helpers::json($response, ['error' => 'Wrong username or password'], 401);
    }

    $hasTotp   = !empty($user[0]['totp_secret']);
    $onSafeNet = false;
    foreach (Config::get('safe_networks') as $cidr) {
        if (Helpers::clientIpInCidr($cidr)) {
            $onSafeNet = true;
            break;
        }
    }
    $needsTotp = $hasTotp && !$onSafeNet;

    if ($needsTotp) {
        $totpCode = $params['totp'] ?? '';
        if (empty($totpCode)) {
            return Helpers::json($response, ['error' => 'MFA code required'], 401);
        }
        if (!Helpers::totpVerify($user[0]['totp_secret'], $totpCode)) {
            return Helpers::json($response, ['error' => 'Invalid MFA code'], 401);
        }
    }

    return Helpers::json($response, Helpers::jwtBuild([
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
    $user_id = Helpers::jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug,
        totp_secret IS NOT NULL AS has_totp
    FROM users");
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->get('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug,
        totp_secret IS NOT NULL AS has_totp
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->put('/v1/changepassword/{id}', function (Request $request, Response $response, array $args): Response {
    $decoded  = Helpers::jwtRequireMfa($request);
    $user_id   = (int) $decoded->data->id;
    $params   = $request->getParsedBody() ?? [];
    $password = $params['password'] ?? '';

    if (empty($password)) {
        return Helpers::json($response, ['error' => 'Please provide a password'], 401);
    }

    // 403, not 401: the caller is authenticated, they are just not allowed to
    // change this particular user's password. Matches every other authorization
    // refusal in the codebase.
    if (($args['id'] != $decoded->data->id) && ($decoded->data->admin != 1)) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
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
    Helpers::logChanges('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->put('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // load the row first: an unknown id is a 404 rather than a silent no-op, and
    // every field the caller omits falls back to its current value instead of
    // being overwritten with NULL (same spirit as password, which has always
    // been omit-to-leave-unchanged here)
    $current = R::getRow("SELECT * FROM users WHERE id = :id", [':id' => $args['id']]);
    if (empty($current)) {
        return Helpers::json($response, ['error' => 'User not found'], 404);
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
            return Helpers::json($response, ['error' => "username '{$username}' is already taken"], 400);
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
    Helpers::logChanges('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Helpers::requireFields($params, ['username', 'password'])) {
        return Helpers::json($response, ['error' => $err], 400);
    }

    // pre-check the UNIQUE column, so a collision comes back as a 400 with a
    // clear message instead of an uncaught SQL error surfacing as a 500
    $taken = R::getRow("SELECT username FROM users WHERE username = :username", [
        ':username' => $params['username'],
    ]);
    if ( ! empty($taken)) {
        return Helpers::json($response, ['error' => "username '{$params['username']}' is already taken"], 400);
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
        // 0 means "no quota" -- see the max_operations check in routes/domain.php
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
    Helpers::logChanges('users', $id, 'create', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ], 201);
});

$app->delete('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);

    R::exec("UPDATE users SET active = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int)$args['id'], 'delete', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $user = R::getAll("SELECT id, username FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Helpers::json($response, ['error' => 'User not found'], 404);
    }

    $totp = Helpers::totpGenerate($user[0]['username']);

    R::exec("UPDATE users SET totp_secret_pending = :secret WHERE id = :id", [
        ':secret' => $totp['secret'],
        ':id'     => $args['id'],
    ]);

    return Helpers::json($response, [
        'secret' => $totp['secret'],
        'uri'    => $totp['uri'],
    ]);
});

$app->put('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $params   = $request->getParsedBody() ?? [];
    $totpCode = $params['totp'] ?? '';

    $user = R::getAll("SELECT id, totp_secret_pending FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Helpers::json($response, ['error' => 'User not found'], 404);
    }
    if (empty($user[0]['totp_secret_pending'])) {
        return Helpers::json($response, ['error' => 'No pending TOTP setup found'], 400);
    }
    if (empty($totpCode) || !Helpers::totpVerify($user[0]['totp_secret_pending'], $totpCode)) {
        return Helpers::json($response, ['error' => 'Invalid TOTP code'], 401);
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
    Helpers::logChanges('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->delete('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtRequireMfa($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
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
    Helpers::logChanges('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    return Helpers::json($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    $user = R::getAll("SELECT id FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Helpers::json($response, ['error' => 'User not found'], 404);
    }

    $params = $request->getParsedBody() ?? [];
    // absolute unix timestamp; 0 = no expiry (infinite)
    $expires = isset($params['expires']) ? (int) $params['expires'] : 0;

    // 32 random bytes as an opaque hex token -- stored only as a hash, same as passwords
    $token = bin2hex(random_bytes(32));
    R::exec("UPDATE users SET api_token = :token, api_token_expires = :expires WHERE id = :id", [
        ':token'   => hash('sha256', $token),
        ':expires' => $expires,
        ':id'      => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    Helpers::logChanges('users', (int) $args['id'], 'update', ['api_token_expires' => $expires], $user_id);

    // the plaintext token is only ever shown here, at issue time -- it can't be
    // recovered later since only its hash is stored
    return Helpers::json($response, [
        'token'   => $token,
        'expires' => $expires,
    ]);
});

$app->delete('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        return Helpers::json($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    R::exec("UPDATE users SET api_token = NULL, api_token_expires = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    Helpers::logChanges('users', (int) $args['id'], 'update', ['api_token' => null], $user_id);

    return Helpers::json($response, ['revoked' => true]);
});
