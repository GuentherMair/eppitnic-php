<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use phpWhois\Whois;

$app->get('/v1/whois', function (Request $request, Response $response, array $args): Response {
    jwtVerify($request);

    $domain = trim($request->getQueryParams()['domain'] ?? '');
    if ($domain === '') {
        $response->getBody()->write(json_encode(['error' => "Missing or empty 'domain' parameter"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $whois = new Whois();
        $result = $whois->lookup($domain, false);

        // collapse a multi-value status field into a single readable string
        if (isset($result['regrinfo']['domain']['status']) && is_array($result['regrinfo']['domain']['status'])) {
            $result['regrinfo']['domain']['status'] = "multiple status fields (see detailed output)";
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode(['error' => 'WHOIS lookup failed: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
});
