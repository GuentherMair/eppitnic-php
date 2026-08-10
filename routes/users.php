<?php

use Net\EPP\Config;
use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/users/renew-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $response->getBody()->write(json_encode(Helpers::jwtBuild((array) $decoded->data)));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/users/me', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $response->getBody()->write(json_encode((array) $decoded->data));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users/authenticate', function (Request $request, Response $response, array $args): Response {
    $params   = $request->getParsedBody() ?? [];
    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    if (empty($username)) {
        $response->getBody()->write(json_encode(['error' => 'Please provide a username']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if (empty($password)) {
        $response->getBody()->write(json_encode(['error' => 'Please provide a password']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $user = R::getAll("SELECT
        id, admin, username, password, totp_secret, debug_level,
        max_token_age, max_idle_time
    FROM users WHERE username = :username AND active = 1", [
        ':username' => $username,
    ]);

    if (empty($user) || !password_verify($password, $user[0]['password'])) {
        $response->getBody()->write(json_encode(['error' => 'Wrong username or password']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
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
            $response->getBody()->write(json_encode(['error' => 'MFA code required']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        if (!Helpers::totpVerify($user[0]['totp_secret'], $totpCode)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid MFA code']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    $response->getBody()->write(json_encode(Helpers::jwtBuild([
        'id'            => $user[0]['id'],
        'admin'         => $user[0]['admin'],
        'username'      => $user[0]['username'],
        'has_totp'      => $hasTotp,
        'needs_totp'    => $needsTotp,
        'totp_verified' => $hasTotp,
        'debug_level'    => $user[0]['debug_level'],
        'max_token_age'   => $user[0]['max_token_age'],
        'max_idle_time'   => $user[0]['max_idle_time'],
    ])));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level,
        totp_secret IS NOT NULL AS has_totp
    FROM users");
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level,
        totp_secret IS NOT NULL AS has_totp
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->put('/v1/changepassword/{id}', function (Request $request, Response $response, array $args): Response {
    $decoded  = Helpers::jwtRequireMfa($request);
    $user_id   = (int) $decoded->data->id;
    $params   = $request->getParsedBody() ?? [];
    $password = $params['password'] ?? '';

    if (empty($password)) {
        $response->getBody()->write(json_encode(['error' => 'Please provide a password']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if (($args['id'] != $decoded->data->id) && ($decoded->data->admin != 1)) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
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
        id, active, admin, username, 'PASSWORD_CHANGED' AS password, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->put('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    if (!empty($params['password'])) {
        R::exec("
            UPDATE users SET
                active         = :active,
                admin          = :admin,
                username       = :username,
                password       = :password,
                max_token_age  = :max_token_age,
                max_idle_time  = :max_idle_time,
                debug_level    = :debug_level
            WHERE id = :id
        ", [
            ':active'        => $params['active'],
            ':admin'         => $params['admin'],
            ':username'      => $params['username'],
            ':password'      => password_hash($params['password'], PASSWORD_DEFAULT),
            ':max_token_age' => $params['max_token_age'],
            ':max_idle_time' => $params['max_idle_time'],
            ':debug_level'   => $params['debug_level'],
            ':id'            => $args['id'],
        ]);
    } else {
        R::exec("
            UPDATE users SET
                active         = :active,
                admin          = :admin,
                username       = :username,
                max_token_age  = :max_token_age,
                max_idle_time  = :max_idle_time,
                debug_level    = :debug_level
            WHERE id = :id
        ", [
            ':active'        => $params['active'],
            ':admin'         => $params['admin'],
            ':username'      => $params['username'],
            ':max_token_age' => $params['max_token_age'],
            ':max_idle_time' => $params['max_idle_time'],
            ':debug_level'   => $params['debug_level'],
            ':id'            => $args['id'],
        ]);
    }

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    R::exec("
        INSERT INTO users (
            active, admin, username, password,
            max_token_age, max_idle_time, debug_level
        ) VALUES (
            :active, :admin, :username, :password,
            :max_token_age, :max_idle_time, :debug_level
        )
    ", [
        ':active'        => $params['active'],
        ':admin'         => $params['admin'],
        ':username'      => $params['username'],
        ':password'      => password_hash($params['password'], PASSWORD_DEFAULT),
        ':max_token_age' => $params['max_token_age'],
        ':max_idle_time' => $params['max_idle_time'],
        ':debug_level'   => $params['debug_level'],
    ]);

    $id = R::getInsertID();
    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $id,
    ]);
    Helpers::logChanges('users', $id, 'create', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);

    R::exec("UPDATE users SET active = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $users = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int)$args['id'], 'delete', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $user = R::getAll("SELECT id, username FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        $response->getBody()->write(json_encode(['error' => 'User not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $totp = Helpers::totpGenerate($user[0]['username']);

    R::exec("UPDATE users SET totp_secret_pending = :secret WHERE id = :id", [
        ':secret' => $totp['secret'],
        ':id'     => $args['id'],
    ]);

    $response->getBody()->write(json_encode([
        'secret' => $totp['secret'],
        'uri'    => $totp['uri'],
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->put('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $params   = $request->getParsedBody() ?? [];
    $totpCode = $params['totp'] ?? '';

    $user = R::getAll("SELECT id, totp_secret_pending FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        $response->getBody()->write(json_encode(['error' => 'User not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if (empty($user[0]['totp_secret_pending'])) {
        $response->getBody()->write(json_encode(['error' => 'No pending TOTP setup found']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if (empty($totpCode) || !Helpers::totpVerify($user[0]['totp_secret_pending'], $totpCode)) {
        $response->getBody()->write(json_encode(['error' => 'Invalid TOTP code']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("UPDATE users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    $users  = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtRequireMfa($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if (!$isOwner && !$isAdmin) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("UPDATE users SET totp_secret = NULL, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    $users  = R::getAll("SELECT
        id, active, admin, username, max_token_age, max_idle_time, debug_level
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    Helpers::logChanges('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $user = R::getAll("SELECT id FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        $response->getBody()->write(json_encode(['error' => 'User not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
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
    $response->getBody()->write(json_encode([
        'token'   => $token,
        'expires' => $expires,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $isAdmin = (int) $decoded->data->admin === 1;
    $isOwner = (int) $decoded->data->id === (int) $args['id'];

    if ( ! $isOwner && ! $isAdmin) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to perform this operation']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("UPDATE users SET api_token = NULL, api_token_expires = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    Helpers::logChanges('users', (int) $args['id'], 'update', ['api_token' => null], $user_id);

    $response->getBody()->write(json_encode(['revoked' => true]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
