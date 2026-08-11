<?php

use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use phpWhois\Whois;

$app->get('/v1/whois', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtVerify($request);

    $domain = trim($request->getQueryParams()['domain'] ?? '');
    if ($domain === '') {
        return Helpers::json($response, ['error' => "Missing or empty 'domain' parameter"], 400);
    }

    try {
        $whois = new Whois();
        $result = $whois->lookup($domain, false);

        // collapse a multi-value status field into a single readable string
        if (isset($result['regrinfo']['domain']['status']) && is_array($result['regrinfo']['domain']['status'])) {
            $result['regrinfo']['domain']['status'] = "multiple status fields (see detailed output)";
        }

        return Helpers::json($response, $result);
    } catch (\Throwable $e) {
        return Helpers::json($response, ['error' => 'WHOIS lookup failed: ' . $e->getMessage()], 500);
    }
});
