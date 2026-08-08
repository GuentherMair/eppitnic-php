<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

// Add the BodyParsingMiddleware in order to automatically parse the Request-Body
// and provide it as a PHP array/object through $request->getParsedBody()
$app->addBodyParsingMiddleware();

$app->add(function (Request $request, RequestHandler $handler): Response {
    $uri  = $request->getUri();
    $path = $uri->getPath();
    if ($path !== '/' && str_ends_with($path, '/')) {
        $request = $request->withUri($uri->withPath(rtrim($path, '/')));
    }
    return $handler->handle($request);
});

// Add the ErrorMiddleware before the CORS middleware
// to ensure error responses contain all CORS headers.
$app->addErrorMiddleware(true, true, true);

// This CORS middleware will append the response header
// Access-Control-Allow-Methods with all allowed methods
$app->add(function (Request $request, RequestHandler $handler) use ($app): Response {
  $origin = $request->getHeaderLine('Origin');
  $isAllowed = in_array($origin, getConfig('allowed_origins'), strict: true);

  if ($request->getMethod() === 'OPTIONS') {
    $statusCode = $isAllowed ? 204 : 403;
    $response = $app->getResponseFactory()->createResponse($statusCode);
  } else {
    if (!$isAllowed) {
      $response = $app->getResponseFactory()->createResponse(403);
      $response->getBody()->write(json_encode(['error' => "CORS: Origin [{$origin}] not allowed"]));
      // by NOT calling $handler->handle() the middleware-chain is broken and no further routes will be executed
      return $response->withHeader('Content-Type', 'application/json');
    }
    $response = $handler->handle($request);
  }

  if ($isAllowed) {
    $response = $response
      ->withHeader('Access-Control-Allow-Credentials', 'true')
      ->withHeader('Access-Control-Allow-Origin', $origin)
      ->withHeader('Access-Control-Allow-Headers', implode(', ', getConfig('allowed_headers')))
      ->withHeader('Access-Control-Allow-Methods', implode(', ', getConfig('allowed_methods')))
      ->withHeader('Access-Control-Expose-Headers', 'Content-Disposition') // filename=".."
      ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
      ->withHeader('Pragma', 'no-cache')
      ->withHeader('Vary', 'Origin'); // important in case of dynamic origins!
  }

  if (ob_get_contents()) {
    ob_clean();
  }

  return $response;
});
