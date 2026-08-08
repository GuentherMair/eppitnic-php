<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/users/renew-token', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $response->getBody()->write(json_encode(jwtBuild((array) $decoded->data)));
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
        id, admin, username, password, totp_secret, debugLevel, debugModules,
        maxTokenAge, maxIdleTime, refreshPage
    FROM users WHERE username = :username AND active = 1", [
        ':username' => $username,
    ]);

    if (empty($user) || !password_verify($password, $user[0]['password'])) {
        $response->getBody()->write(json_encode(['error' => 'Wrong username or password']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $hasTotp   = !empty($user[0]['totp_secret']);
    $onSafeNet = false;
    foreach (getConfig('safe_networks') as $cidr) {
        if (clientIpInCidr($cidr)) {
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
        if (!totpVerify($user[0]['totp_secret'], $totpCode)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid MFA code']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    $response->getBody()->write(json_encode(jwtBuild([
        'id'            => $user[0]['id'],
        'admin'         => $user[0]['admin'],
        'username'      => $user[0]['username'],
        'has_totp'      => $hasTotp,
        'needs_totp'    => $needsTotp,
        'totp_verified' => $hasTotp,
        'debugLevel'    => $user[0]['debugLevel'],
        'debugModules'  => $user[0]['debugModules'],
        'maxTokenAge'   => $user[0]['maxTokenAge'],
        'maxIdleTime'   => $user[0]['maxIdleTime'],
        'refreshPage'   => $user[0]['refreshPage'],
    ])));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules,
        totp_secret IS NOT NULL AS has_totp
    FROM users");
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = jwtUserID($request);

    $users = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules,
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
    $decoded  = jwtRequireMfa($request);
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
        id, active, admin, username, 'PASSWORD_CHANGED' AS password, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    changelogInsert('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->put('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    if (!empty($params['password'])) {
        R::exec("
            UPDATE users SET
                active       = :active,
                admin        = :admin,
                username     = :username,
                password     = :password,
                maxTokenAge  = :maxTokenAge,
                maxIdleTime  = :maxIdleTime,
                refreshPage  = :refreshPage,
                debugLevel   = :debugLevel,
                debugModules = :debugModules
            WHERE id = :id
        ", [
            ':active'       => $params['active'],
            ':admin'        => $params['admin'],
            ':username'     => $params['username'],
            ':password'     => password_hash($params['password'], PASSWORD_DEFAULT),
            ':maxTokenAge'  => $params['maxTokenAge'],
            ':maxIdleTime'  => $params['maxIdleTime'],
            ':refreshPage'  => $params['refreshPage'],
            ':debugLevel'   => $params['debugLevel'],
            ':debugModules' => $params['debugModules'],
            ':id'           => $args['id'],
        ]);
    } else {
        R::exec("
            UPDATE users SET
                active       = :active,
                admin        = :admin,
                username     = :username,
                maxTokenAge  = :maxTokenAge,
                maxIdleTime  = :maxIdleTime,
                refreshPage  = :refreshPage,
                debugLevel   = :debugLevel,
                debugModules = :debugModules
            WHERE id = :id
        ", [
            ':active'       => $params['active'],
            ':admin'        => $params['admin'],
            ':username'     => $params['username'],
            ':maxTokenAge'  => $params['maxTokenAge'],
            ':maxIdleTime'  => $params['maxIdleTime'],
            ':refreshPage'  => $params['refreshPage'],
            ':debugLevel'   => $params['debugLevel'],
            ':debugModules' => $params['debugModules'],
            ':id'           => $args['id'],
        ]);
    }

    $users = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    changelogInsert('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users', function (Request $request, Response $response, array $args): Response {
    $user_id = jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    R::exec("
        INSERT INTO users (
            active, admin, username, password,
            maxTokenAge, maxIdleTime, refreshPage, debugLevel, debugModules
        ) VALUES (
            :active, :admin, :username, :password,
            :maxTokenAge, :maxIdleTime, :refreshPage, :debugLevel, :debugModules
        )
    ", [
        ':active'       => $params['active'],
        ':admin'        => $params['admin'],
        ':username'     => $params['username'],
        ':password'     => password_hash($params['password'], PASSWORD_DEFAULT),
        ':maxTokenAge'  => $params['maxTokenAge'],
        ':maxIdleTime'  => $params['maxIdleTime'],
        ':refreshPage'  => $params['refreshPage'],
        ':debugLevel'   => $params['debugLevel'],
        ':debugModules' => $params['debugModules'],
    ]);

    $id = R::getInsertID();
    $users = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $id,
    ]);
    changelogInsert('users', $id, 'create', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user_id = jwtRequireAdmin($request);

    R::exec("UPDATE users SET active = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $users = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    changelogInsert('users', (int)$args['id'], 'delete', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
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

    $totp = totpGenerate($user[0]['username']);

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
    $decoded = jwtVerify($request);
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
    if (empty($totpCode) || !totpVerify($user[0]['totp_secret_pending'], $totpCode)) {
        $response->getBody()->write(json_encode(['error' => 'Invalid TOTP code']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("UPDATE users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $user_id = (int) $decoded->data->id;
    $users  = R::getAll("SELECT
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    changelogInsert('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/users/{id}/totp', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtRequireMfa($request);
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
        id, active, admin, username, maxTokenAge, maxIdleTime,
        refreshPage, debugLevel, debugModules
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    changelogInsert('users', (int) $args['id'], 'update', $users[0] ?? [], $user_id);
    $response->getBody()->write(json_encode([
        'users' => $users,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
