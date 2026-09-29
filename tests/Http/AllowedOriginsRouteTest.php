<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `GET`/`PUT /v1/allowed-origins` -- the API surface over AllowedOrigins
 * (see AllowedOriginsTest for that layer's own validation coverage).
 */
final class AllowedOriginsRouteTest extends TestCase
{
    private const ORIGIN = 'https://epp.example.it';

    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'allowed_origins' => [self::ORIGIN],
        'allowed_headers' => ['Authorization', 'Content-Type'],
        'allowed_methods' => ['GET', 'PUT', 'OPTIONS'],
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'          => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting(self::SETTINGS);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/allowed_origins.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function request(\Slim\App $app, string $method, ?array $body = null, int $admin = 1, ?string $origin = null): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/allowed-origins')
            ->withHeader('Authorization', 'Bearer ' . $this->token($admin));
        if ($origin !== null) {
            $request = $request->withHeader('Origin', $origin);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testGetReturnsTheList(): void {
        $this->assertSame([self::ORIGIN], self::body($this->request($this->app(), 'GET'))['allowed_origins']);
    }

    public function testGetRequiresAdmin(): void {
        $this->assertSame(403, $this->request($this->app(), 'GET', null, 0)->getStatusCode());
    }

    public function testPutReplacesTheListCanonically(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['allowed_origins' => ['https://EPP.example.it/', 'http://127.0.0.1:8095']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::ORIGIN, 'http://127.0.0.1:8095'], self::body($response)['allowed_origins']);
        $this->assertSame([self::ORIGIN, 'http://127.0.0.1:8095'], Config::get('allowed_origins'));
        $this->assertNotEmpty(R::getRow("SELECT * FROM history WHERE object = 'allowed_origins'"));
    }

    public function testABrowserMayRemoveItsOwnOrigin(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['allowed_origins' => []], 1, self::ORIGIN);

        $this->assertSame(200, $response->getStatusCode(), 'checked against the list stored before the write');
        $this->assertSame([], Config::get('allowed_origins'));
    }

    public function testAnUnlistedBrowserOriginCannotWrite(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['allowed_origins' => ['https://evil.example']], 1, 'https://evil.example');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([self::ORIGIN], Config::get('allowed_origins'));
    }

    public function testPutRejectsSomethingThatIsNoOrigin(): void {
        $response = $this->request($this->app(), 'PUT', ['allowed_origins' => ['*']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPutRejectsAMissingList(): void {
        $this->assertSame(400, $this->request($this->app(), 'PUT', ['something' => 'else'])->getStatusCode());
    }

    public function testAManagerIsRefusedToo(): void {
        $app = $this->app();
        $token = TestAccounts::issueToken([
            'id' => 8, 'username' => 'manager', 'role' => 'manager', 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
        foreach (['GET' => null, 'PUT' => ['allowed_origins' => ['https://other.example.it']]] as $method => $body) {
            $request = (new ServerRequestFactory())
                ->createServerRequest($method, 'http://localhost/v1/allowed-origins')
                ->withHeader('Authorization', 'Bearer ' . $token);
            if ($body !== null) {
                $request = $request->withParsedBody($body);
            }
            $this->assertSame(403, $app->handle($request)->getStatusCode(), "{$method} as a manager");
        }
        $this->assertSame([self::ORIGIN], Config::get('allowed_origins'));
    }

    public function testPutRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['allowed_origins' => ['https://other.example.it']], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([self::ORIGIN], Config::get('allowed_origins'));
    }
}
