<?php

use Net\EPP\Client;
use Net\EPP\Config;
use Net\EPP\Helpers;
use Net\EPP\IT\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/session/credit', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtVerify($request);

    try {
        $credit = Helpers::withEppSession(function ($nic, $session) {
            return $session->showCredit();
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['credit' => $credit]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/poll-queue', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $params = $request->getQueryParams();
    $activeOnly = ($params['active'] ?? '1') !== '0';

    $where = $activeOnly ? 'archived_time IS NULL' : '1 = 1';
    $messages = R::getAll("SELECT * FROM messages WHERE {$where} ORDER BY id DESC");

    $response->getBody()->write(json_encode(['messages' => $messages]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/poll-queue/{id}', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $id = (int) $args['id'];

    $message = R::getRow("SELECT * FROM messages WHERE id = ?", [$id]);
    if (empty($message)) {
        $response->getBody()->write(json_encode(['error' => "Message id {$id} not found"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['message' => $message]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/poll-queue/{id}/archive', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $id = (int) $args['id'];

    R::exec("UPDATE messages SET archived_time = NOW(), archived_user_id = ? WHERE id = ?", [$user_id, $id]);

    $response->getBody()->write(json_encode(['archived' => true, 'id' => $id]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/session/change-password', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];
    $newPassword = $params['password'] ?? substr(md5(rand()), 0, 8);

    // this is the shared EPP registry credential, not a per-user login password
    // (that's PUT /v1/changepassword/{id}) -- can't go through Helpers::withEppSession()
    // here since its normal login() would already run before we get a chance
    // to make *our* login the one that changes the password
    $nic = new Client();
    $session = new Session($nic);

    if ( ! $session->hello()) {
        $response->getBody()->write(json_encode(['error' => 'EPP session unavailable: connection failed']));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if ($session->login($newPassword) === FALSE) {
        $response->getBody()->write(json_encode(['error' => $session->getError()]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    $session->logout();

    // persist locally -- the registry password just changed, the 'epp' setting must follow
    try {
        $epp = Config::get('epp');
        $epp['password'] = $newPassword;
        Config::set('epp', $epp);
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode(['error' => 'registry password changed but could not persist to settings: ' . $e->getMessage() . ' -- update it manually']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['changed' => true]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
