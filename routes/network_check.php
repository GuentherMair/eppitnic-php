<?php

use Net\EPP\Config;
use Net\EPP\Helpers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/network-check', function (Request $request, Response $response, array $args): Response {
    $safeNetwork = false;
    foreach (Config::get('safe_networks') as $cidr) {
        if (Helpers::clientIpInCidr($cidr)) {
            $safeNetwork = true;
            break;
        }
    }

    $response->getBody()->write(json_encode([
        'safe_network' => $safeNetwork,
        'client_ip'    => Helpers::clientIp(),
    ]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});
