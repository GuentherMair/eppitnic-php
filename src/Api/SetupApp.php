<?php

namespace Eppitnic\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

/**
 * What public/index.php serves before config/config.php exists: the setup
 * routes and public/setup.html, on a Slim instance needing no database --
 * unlike Middleware::register(), whose CORS closure reads a setting.
 *
 * @category    Net
 * @package     Eppitnic\Api\SetupApp
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SetupApp
{
    /**
     * Build the Slim app without running it, so the test suite can dispatch at
     * it directly rather than through the PHP SAPI.
     */
    public function build(): App {
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();

        $displayErrorDetails = filter_var(getenv('EPPITNIC_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app): Response {
                $status = ($exception instanceof HttpException) ? $exception->getCode() : 500;
                $body = ['error' => $exception->getMessage()];
                if ($displayErrorDetails) {
                    $body['exception'] = get_class($exception);
                    $body['file'] = $exception->getFile();
                    $body['line'] = $exception->getLine();
                } elseif ($status === 500) {
                    $body['error'] = 'Internal server error';
                }
                return Json::response($app->getResponseFactory()->createResponse(), $body, $status);
            }
        );

        // Permissive, not the real origin allowlist: these routes are
        // unauthenticated by design, and a separate-origin frontend must reach
        // them before allowed_origins exists to configure
        $app->add(function (Request $request, RequestHandler $handler): Response {
            $response = $handler->handle($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        });

        // require_once: setup.php declares a global function at include time,
        // and SetupRouteTest loads it too -- this keeps the second load a
        // redeclaration-safe no-op
        require_once EPPITNIC_ROOT . '/src/Api/Routes/setup.php';

        $app->get('/', function (Request $request, Response $response): Response {
            $response->getBody()->write((string) file_get_contents(EPPITNIC_ROOT . '/public/setup.html'));
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        return $app;
    }

    public function run(): void {
        $this->build()->run();
    }
}
