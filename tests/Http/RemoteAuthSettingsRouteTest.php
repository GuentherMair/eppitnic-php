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
 * `GET`/`PATCH /v1/remote-auth` -- the API surface over RemoteAuthSettings
 * (see RemoteAuthSettingsTest for that layer's own validation coverage).
 */
final class RemoteAuthSettingsRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => ['10.0.0.0/8'],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'          => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
        'remote_auth'     => ['enabled' => false, 'header' => null],
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
        require EPPITNIC_ROOT . '/src/Api/Routes/remote_auth.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return Auth::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function get(\Slim\App $app, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/remote-auth')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
        );
    }

    private function patch(\Slim\App $app, array $body, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('PATCH', 'http://localhost/v1/remote-auth')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
                ->withParsedBody($body)
        );
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testGetReturnsTheSettingAndTrustedProxies(): void {
        $body = self::body($this->get($this->app()));

        $this->assertSame(['enabled' => false, 'header' => null], $body['remote_auth']);
        $this->assertSame(['10.0.0.0/8'], $body['trusted_proxies']);
    }

    public function testGetRequiresAdmin(): void {
        $this->assertSame(403, $this->get($this->app(), 0)->getStatusCode());
    }

    public function testPatchEnablesHeaderModeAndReturnsTheNewState(): void {
        $app = $this->app();
        $response = $this->patch($app, ['enabled' => true, 'header' => 'X-Remote-User']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['enabled' => true, 'header' => 'X-Remote-User'], self::body($response)['remote_auth']);
        $this->assertSame(['enabled' => true, 'header' => 'X-Remote-User'], Config::get('remote_auth'));
    }

    public function testPatchBlankHeaderGoesBackToServerMode(): void {
        $app = $this->app();
        $this->patch($app, ['header' => 'X-Remote-User']);
        $response = $this->patch($app, ['header' => '']);

        $this->assertNull(self::body($response)['remote_auth']['header']);
    }

    public function testPatchWritesAHistoryRow(): void {
        $app = $this->app();
        $this->patch($app, ['enabled' => true]);

        $row = R::getRow("SELECT * FROM history WHERE object = 'remote_auth'");
        $this->assertNotEmpty($row);
        $this->assertSame('7', (string) $row['user_id']);
    }

    public function testPatchRejectsAForbiddenHeader(): void {
        $response = $this->patch($this->app(), ['header' => 'Authorization']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPatchRejectsAnUnknownField(): void {
        $this->assertSame(400, $this->patch($this->app(), ['bogus' => 1])->getStatusCode());
    }

    public function testPatchRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->patch($app, ['enabled' => true], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(Config::get('remote_auth')['enabled']);
    }
}
