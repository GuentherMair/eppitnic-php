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
 * Error responses, dispatched through the real application.
 *
 * Slim's stock handler renders an HTML page with the exception message, file,
 * line and stack trace in it. From a JSON API that is two problems at once:
 * every client of /v1/* has to special-case a content type it never asked
 * for, and an unauthenticated request is enough to get the server's file
 * paths and call stack back.
 *
 * These tests dispatch real requests through the same middleware stack
 * public/index.php builds, so they fail if either regresses.
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

        // Route *files* need no database to load; the routes that need one
        // reach for it inside their closures, past the auth check these tests
        // stop at. A settings array is installed anyway so that anything
        // touching Config during middleware setup finds it.
        Config::loadForTesting([
            'allowed_origins' => ['https://app.example.com'],
            'allowed_headers' => ['Authorization', 'Content-Type'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'jwt_psk'         => 'test-key',
        ]);

        $app = AppFactory::create();
        Middleware::register($app);

        foreach (['root', 'network_check', 'users', 'session', 'contact', 'domain', 'reminders', 'whois', 'changelog'] as $file) {
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
            'GET /v1/reminders'   => ['GET', '/v1/reminders'],
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
     * CORS headers have to survive on error responses too -- a browser cannot
     * read a 401 it is not allowed to see, which turns "you are not logged in"
     * into an opaque network error in the client. This is why the error
     * middleware is registered before the CORS middleware.
     */
    public function testCorsHeadersArePresentOnErrors(): void {
        $response = $this->request('GET', '/v1/domains', ['Origin' => 'https://app.example.com']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
