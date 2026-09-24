<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `GET /v1/users/me` and `GET /v1/users/renew-token` under remote auth: the
 * `remote_auth: true` claim the frontend depends on, and renew-token's 400
 * (there is no JWT to renew). Auth::verify()'s own resolution rules are
 * AuthRemoteTest's job.
 */
final class RemoteAuthRouteTest extends TestCase
{
    private function app(array $remoteAuth): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'trusted_proxies' => [],
            'remote_auth'     => $remoteAuth,
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, admin INTEGER DEFAULT 0,
                 active INTEGER DEFAULT 1, debug INTEGER DEFAULT 0, max_token_age INTEGER, max_idle_time INTEGER)');
        R::exec("INSERT INTO users (id, username, admin, active) VALUES (1, 'admin', 1, 1), (2, 'someone', 0, 1)");

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function get(\Slim\App $app, string $path, array $serverParams = []): ResponseInterface {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', "http://localhost{$path}", $serverParams));
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testMeCarriesRemoteAuthTrue(): void {
        $app = $this->app(['enabled' => true, 'header' => null]);
        $response = $this->get($app, '/v1/users/me', ['REMOTE_USER' => 'someone']);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['remote_auth']);
        $this->assertSame(2, (int) $body['id']);
        $this->assertSame(0, (int) $body['admin']);
    }

    public function testMeWithoutRemoteAuthHasNoSuchClaim(): void {
        $app = $this->app(['enabled' => false, 'header' => null]);
        $response = $this->get($app, '/v1/users/me');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRenewTokenIsRejectedUnderRemoteAuth(): void {
        $app = $this->app(['enabled' => true, 'header' => null]);
        $response = $this->get($app, '/v1/users/renew-token', ['REMOTE_USER' => 'someone']);
        $body = self::body($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Token renewal is not available with remote authentication', $body['error']);
    }
}
