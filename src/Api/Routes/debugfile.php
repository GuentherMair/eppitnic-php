<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Service\DebugFile;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The `debugfile` setting -- see DebugFile, shared with `config debugfile`.
 * PUT starts a log, DELETE removes the file and only then the setting.
 * `warning` is for a client to show before turning logging on.
 */
$app->get('/v1/debugfile', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    return Json::response($response, ['debugfile' => DebugFile::status(), 'warning' => DebugFile::WARNING]);
});

$app->put('/v1/debugfile', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    if ( ! is_string($body['path'] ?? null) || trim($body['path']) === '') {
        return Json::response($response, ['error' => 'path must be a .log file name -- DELETE turns logging off'], 400);
    }

    try {
        $status = DebugFile::set($body['path'], $userId);
    } catch (\DomainException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 409);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['debugfile' => $status, 'warning' => DebugFile::WARNING]);
});

$app->delete('/v1/debugfile', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);

    try {
        $status = DebugFile::delete($userId);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 500);
    }

    return Json::response($response, ['debugfile' => $status, 'warning' => DebugFile::WARNING]);
});

// the log's end as plain text, for a browser tab; every read is audited
$app->get('/v1/debugfile/content', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);

    $tail = DebugFile::tail();
    if ($tail === null) {
        return Json::response($response, ['error' => 'No debug log to show'], 404);
    }

    History::recordSecurityEvent('debugfile_read', $request, $userId, ['size' => $tail['size']]);

    $head = $tail['truncated']
        ? '[... ' . ($tail['size'] - DebugFile::TAIL_BYTES) . " earlier bytes omitted ...]\n"
        : '';
    $response->getBody()->write($head . $tail['content']);
    return $response
        ->withHeader('Content-Type', 'text/plain; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store');
});
