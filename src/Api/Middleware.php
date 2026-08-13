<?php

namespace Eppitnic\Api;

use Eppitnic\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;
use Slim\Exception\HttpException;

/**
 * The application's global middleware stack: body parsing, trailing-slash
 * normalization, JSON error responses and CORS.
 *
 * @category    Net
 * @package     Eppitnic\Api\Middleware
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Middleware
{
    // -----------------------------------------------------------------
    // Slim middleware (CORS, trailing-slash normalization, error handling)
    // -----------------------------------------------------------------

    /**
     * register the app's global middleware stack: body-parsing, trailing-slash
     * normalization, error handling, and CORS. Called once from public/index.php
     * right after $app is constructed.
     *
     * @param App $app the Slim application instance
     */
    public static function register(App $app): void {
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
        //
        // Slim's own handler renders a full HTML page, which is the wrong
        // content type for every route in this application, so the default
        // handler is replaced below with one that answers in JSON.
        //
        // Error details are off unless EPPITNIC_DEBUG is set: with them on,
        // Slim puts the exception message, file, line and full stack trace in
        // the response body, and an unauthenticated request is enough to get
        // one. Read from the environment rather than from `settings` on
        // purpose -- an unreachable database is exactly when this handler runs
        // and exactly when Config::get() cannot answer.
        $displayErrorDetails = filter_var(getenv('EPPITNIC_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app): Response {
                // an HttpException carries the status the route intended
                // (401/403/404/405); anything else escaped a handler and is a 500
                $status = ($exception instanceof HttpException) ? $exception->getCode() : 500;

                $body = ['error' => $exception->getMessage()];
                if ($displayErrorDetails) {
                    $body['exception'] = get_class($exception);
                    $body['file'] = $exception->getFile();
                    $body['line'] = $exception->getLine();
                    $body['trace'] = $exception->getTraceAsString();
                } elseif ($status === 500) {
                    // never leak an internal failure's message to a client;
                    // logErrors is on, so the real one is in the server log
                    $body['error'] = 'Internal server error';
                }

                return Json::response($app->getResponseFactory()->createResponse(), $body, $status);
            }
        );

        // This CORS middleware will append the response header
        // Access-Control-Allow-Methods with all allowed methods
        $app->add(function (Request $request, RequestHandler $handler) use ($app): Response {
            $origin = $request->getHeaderLine('Origin');

            // A request without an Origin header is not a browser cross-origin
            // request: curl, cron jobs and fixed-API-token clients all land here.
            // CORS is something browsers enforce on top of an Origin, so with
            // none present there is nothing to police, and an empty
            // Access-Control-Allow-Origin header would be meaningless anyway.
            // This used to be expressed by keeping "" in allowed_origins, which
            // made a security-relevant behaviour hinge on an invisible empty
            // string -- and broke every scripted client the moment an operator
            // configured a real origin list over the placeholder.
            if ($origin === '') {
                $response = $handler->handle($request);
                if (ob_get_contents()) {
                    ob_clean();
                }
                return $response;
            }

            $isAllowed = in_array($origin, Config::get('allowed_origins'), strict: true);

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
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', Config::get('allowed_headers')))
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', Config::get('allowed_methods')))
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
    }
}
