<?php

use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/accounting', function (Request $request, Response $response, array $args): Response {
    $decoded = Helpers::jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $params  = $request->getQueryParams();

    $page = max(1, (int) ($params['page'] ?? 1));
    $pageSize = min(200, max(1, (int) ($params['pageSize'] ?? 25)));

    $where = ['1 = 1'];
    $bind = [];
    if ( ! $isAdmin) {
        $user = R::getRow("SELECT billing_id FROM users WHERE id = ?", [$user_id]);
        $where[] = 'billing_id = :billing_id';
        $bind[':billing_id'] = $user['billing_id'] ?? '';
    }
    if ( ! empty($params['search'])) {
        $where[] = '(object LIKE :search OR operation LIKE :search)';
        $bind[':search'] = '%' . $params['search'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) R::getCell("SELECT COUNT(*) FROM accounting WHERE {$whereSql}", $bind);
    $rows = R::getAll("
        SELECT id, operation, billing_id, object, date, time, status FROM accounting
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

$app->get('/v1/accounting/forecast', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    $days = (int) ($request->getQueryParams()['days'] ?? 30);

    $items = (int) R::getCell("SELECT COUNT(1) FROM domains WHERE ex_date < DATE_ADD(current_timestamp, INTERVAL ? DAY)", [$days]);
    $yearly = (int) R::getCell("SELECT COUNT(1) FROM domains WHERE cr_date > DATE_SUB(current_timestamp, INTERVAL 1 YEAR)");
    $forecast = (float) ($items + ($yearly / 360 * $days));

    $response->getBody()->write(json_encode(['forecast' => $forecast, 'days' => $days]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/accounting/close', function (Request $request, Response $response, array $args): Response {
    $user_id = Helpers::jwtRequireAdmin($request);
    $params = $request->getParsedBody() ?? [];
    $ids = array_map('intval', (array) ($params['ids'] ?? []));

    if (empty($ids)) {
        $response->getBody()->write(json_encode(['error' => 'ids is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    R::exec("UPDATE accounting SET status = 1 WHERE status = 0 AND id IN ({$placeholders})", $ids);

    $response->getBody()->write(json_encode(['closed' => count($ids)]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
