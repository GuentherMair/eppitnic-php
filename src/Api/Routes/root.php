<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// add a hello world route callbacks
$app->get('/', function (Request $request, Response $response, array $args) {
  $response->getBody()->write('Hello, World!');
  return $response;
});

