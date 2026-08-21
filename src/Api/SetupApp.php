<?php

namespace Eppitnic\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

/**
 * What public/index.php serves in place of the real application when
 * config/config.php doesn't exist yet: the three setup routes
 * (src/Api/Routes/setup.php) and the bundled fallback page
 * (public/setup.html) that drives them, on a Slim instance that needs no
 * database at all -- unlike Middleware::register(), whose CORS closure reads
 * Config::get('allowed_origins') and would fatal here the same way
 * public/index.php itself used to before config existed.
 *
 * @category    Net
 * @package     Eppitnic\Api\SetupApp
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SetupApp
{
    /**
     * Builds the Slim app without running it -- split out from run() so the
     * test suite can dispatch requests at it directly (App::handle()) rather
     * than through the real PHP SAPI, the way ErrorResponseTest already does
     * for the configured application.
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

        // Permissive rather than the real Middleware's origin allowlist:
        // these routes are unauthenticated by design (see
        // src/Api/Routes/setup.php), and a separate-origin frontend has to
        // reach this before allowed_origins even exists to configure it.
        $app->add(function (Request $request, RequestHandler $handler): Response {
            $response = $handler->handle($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        });

        // require_once: setup.php declares a global function at include
        // time, and the test suite's SetupRouteTest also loads this file
        // directly against its own Slim instance -- require_once is what
        // keeps that a redeclaration-safe no-op the second time either one
        // (or a repeated build() call) runs in the same process.
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
