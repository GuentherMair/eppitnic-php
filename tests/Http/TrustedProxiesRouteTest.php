<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `GET`/`PUT /v1/trusted-proxies` -- the API surface over TrustedProxies
 * (see TrustedProxiesTest for that layer's own validation coverage).
 */
final class TrustedProxiesRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => ['10.0.0.0/8'],
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
        require EPPITNIC_ROOT . '/src/Api/Routes/trusted_proxies.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return Auth::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function request(\Slim\App $app, string $method, ?array $body = null, int $admin = 1): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/trusted-proxies', ['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->token($admin));
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

    public function testGetReturnsTheListAndThePeer(): void {
        $body = self::body($this->request($this->app(), 'GET'));

        $this->assertSame(['10.0.0.0/8'], $body['trusted_proxies']);
        $this->assertSame('172.18.0.1', $body['peer']);
    }

    public function testGetRequiresAdmin(): void {
        $this->assertSame(403, $this->request($this->app(), 'GET', null, 0)->getStatusCode());
    }

    public function testPutReplacesTheListCanonically(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['trusted_proxies' => ['172.18.0.1', '10.1.2.3/8']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['172.18.0.1/32', '10.0.0.0/8'], self::body($response)['trusted_proxies']);
        $this->assertSame(['172.18.0.1/32', '10.0.0.0/8'], Config::get('trusted_proxies'));
        $this->assertNotEmpty(R::getRow("SELECT * FROM history WHERE object = 'trusted_proxies'"));
    }

    public function testPutRejectsACatchAll(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['trusted_proxies' => ['0.0.0.0/0']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['10.0.0.0/8'], Config::get('trusted_proxies'));
    }

    public function testPutRejectsSomethingThatIsNotANetwork(): void {
        $response = $this->request($this->app(), 'PUT', ['trusted_proxies' => ['not-an-address']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPutRejectsAMissingList(): void {
        $this->assertSame(400, $this->request($this->app(), 'PUT', ['something' => 'else'])->getStatusCode());
    }

    public function testPutRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', ['trusted_proxies' => ['172.18.0.1']], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['10.0.0.0/8'], Config::get('trusted_proxies'));
    }
}
