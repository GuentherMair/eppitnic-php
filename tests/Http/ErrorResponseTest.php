<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Error responses, dispatched through the real application. Slim's stock
 * handler renders HTML with a stack trace in it -- the wrong content type for
 * every route, and a call stack an unauthenticated request can have.
 */
final class ErrorResponseTest extends TestCase
{
    /**
     * The routes are procedural: they declare global functions and a const at
     * include time, so they can only be loaded once per process. The app is
     * therefore built once and shared.
     */
    private static ?App $app = null;

    private static function app(): App {
        if (self::$app !== null) {
            return self::$app;
        }

        // Route *files* need no database to load; those that do reach for it
        // inside their closures, past the auth check these stop at. Settings
        // are installed anyway, for anything touching Config during setup
        Config::loadForTesting([
            'allowed_origins' => ['https://app.example.com'],
            'allowed_headers' => ['Authorization', 'Content-Type'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'jwt_psk'         => 'test-key',
        ]);

        $app = AppFactory::create();
        Middleware::register($app);

        foreach (['root', 'network_check', 'users', 'session', 'contact', 'domain', 'tasks', 'cronjobs', 'smtp', 'remote_auth', 'trusted_proxies', 'whois', 'history'] as $file) {
            require EPPITNIC_ROOT . "/src/Api/Routes/{$file}.php";
        }

        return self::$app = $app;
    }

    private function request(string $method, string $path, array $headers = []): \Psr\Http\Message\ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}");
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return self::app()->handle($request);
    }

    /**
     * One per distinct guard: plain JWT, admin-only, and MFA-gated.
     */
    public static function protectedRoutes(): array {
        return [
            'GET /v1/domains'     => ['GET', '/v1/domains'],
            'GET /v1/contacts'    => ['GET', '/v1/contacts'],
            'GET /v1/users'       => ['GET', '/v1/users'],
            'GET /v1/poll-queue'  => ['GET', '/v1/poll-queue'],
            'GET /v1/session/epp/credentials' => ['GET', '/v1/session/epp/credentials'],
            'GET /v1/tasks'       => ['GET', '/v1/tasks'],
            'GET /v1/cronjobs'    => ['GET', '/v1/cronjobs'],
            'POST /v1/domains'    => ['POST', '/v1/domains'],
            'DELETE /v1/users/1'  => ['DELETE', '/v1/users/1'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function testUnauthenticatedRequestGetsJson(string $method, string $path): void {
        $response = $this->request($method, $path);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'response body is not JSON: ' . substr((string) $response->getBody(), 0, 120));
        $this->assertArrayHasKey('error', $body);
        $this->assertNotSame('', $body['error']);
    }

    /**
     * The disclosure half of the problem: no file paths, no line numbers, no
     * stack trace, whatever the failure was.
     */
    #[DataProvider('protectedRoutes')]
    public function testErrorResponseLeaksNoInternals(string $method, string $path): void {
        $body = (string) $this->request($method, $path)->getBody();

        foreach (['trace', 'exception', '.php', '#0 ', 'Net\\EPP\\'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "response leaks '{$forbidden}'");
        }
    }

    public function testUnknownRouteIsJson404(): void {
        $response = $this->request('GET', '/v1/no-such-route');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertArrayHasKey('error', json_decode((string) $response->getBody(), true));
    }

    public function testWrongMethodIsJson405(): void {
        $response = $this->request('PATCH', '/v1/users');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    /**
     * A route that succeeds must be unaffected by any of this.
     */
    public function testSuccessfulRouteStillWorks(): void {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * CORS headers must survive on error responses: a browser cannot read a 401
     * it is not allowed to see, turning "you are not logged in" into an opaque
     * network error. Hence the error middleware registering first.
     */
    public function testCorsHeadersArePresentOnErrors(): void {
        $response = $this->request('GET', '/v1/domains', ['Origin' => 'https://app.example.com']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
