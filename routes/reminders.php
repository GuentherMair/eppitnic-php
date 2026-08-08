<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/reminders', function (Request $request, Response $response, array $args): Response {
    jwtRequireAdmin($request);
    $params = $request->getQueryParams();

    $page = max(1, (int) ($params['page'] ?? 1));
    $pageSize = min(200, max(1, (int) ($params['pageSize'] ?? 25)));

    $where = ['1 = 1'];
    $bind = [];
    if (isset($params['action']) && $params['action'] !== '') {
        $where[] = $params['action'] === 'null' ? 'action IS NULL' : 'action = :action';
        if ($params['action'] !== 'null') {
            $bind[':action'] = $params['action'];
        }
    }
    if (isset($params['active']) && $params['active'] !== '') {
        $where[] = 'active = :active';
        $bind[':active'] = (int) $params['active'];
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) R::getCell("SELECT COUNT(*) FROM reminder WHERE {$whereSql}", $bind);
    $rows = R::getAll("
        SELECT id, domain, date, notice, email, action, active, created_time FROM reminder
        WHERE {$whereSql}
        ORDER BY id DESC
        LIMIT " . (($page - 1) * $pageSize) . ", " . $pageSize, $bind);

    $response->getBody()->write(json_encode([
        'total' => $total,
        'filteredTotal' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'rows' => $rows,
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/domains/{name}/reminders', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];

    $where = ['d.domain = r.domain', 'r.active = 1', 'd.domain = :domain'];
    $bind = [':domain' => $name];
    if ( ! $isAdmin) {
        $where[] = 'd.user_id = :user_id';
        $bind[':user_id'] = $user_id;
    }

    $reminders = R::getAll("
        SELECT r.id, r.date, r.domain, r.email, r.notice
        FROM domains d, reminder r
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.date DESC", $bind);

    $response->getBody()->write(json_encode(['reminders' => $reminders]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/reminders', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['date']) || empty($params['notice'])) {
        $response->getBody()->write(json_encode(['error' => 'date and notice are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $where = ['domain = :domain'];
    $bind = [':domain' => $name];
    if ( ! $isAdmin) {
        $where[] = 'user_id = :user_id';
        $bind[':user_id'] = $user_id;
    }
    $owns = (int) R::getCell("SELECT COUNT(*) FROM domains WHERE " . implode(' AND ', $where), $bind);
    if ($owns !== 1) {
        $response->getBody()->write(json_encode(['error' => "Domain '{$name}' does not belong to this user"]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("INSERT INTO reminder (domain, date, notice, email) VALUES (:domain, :date, :notice, :email)", [
        ':domain' => $name,
        ':date'   => $params['date'],
        ':notice' => $params['notice'],
        ':email'  => $params['email'] ?? '',
    ]);

    $response->getBody()->write(json_encode(['created' => true, 'domain' => $name]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/reminders/{id}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $id = (int) $args['id'];

    $where = ['r.id = :id', 'r.domain = d.domain'];
    $bind = [':id' => $id];
    if ( ! $isAdmin) {
        $where[] = 'd.user_id = :user_id';
        $bind[':user_id'] = $user_id;
    }
    $owns = (int) R::getCell("SELECT COUNT(*) FROM domains d, reminder r WHERE " . implode(' AND ', $where), $bind);
    if ($owns !== 1) {
        $response->getBody()->write(json_encode(['error' => "Reminder not found or does not belong to this user"]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    R::exec("UPDATE reminder SET active = 0 WHERE id = ?", [$id]);

    $response->getBody()->write(json_encode(['archived' => true, 'id' => $id]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
