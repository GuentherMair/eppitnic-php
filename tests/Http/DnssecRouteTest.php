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

/** `GET`/`PATCH /v1/dnssec` -- the API surface over DnssecSettings. */
final class DnssecRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'          => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT'],
        'remote_auth'     => ['enabled' => false, 'header' => null],
        'dnssec'          => ['active' => 0],
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
        require EPPITNIC_ROOT . '/src/Api/Routes/dnssec.php';
        return $app;
    }

    private function request(\Slim\App $app, string $method, ?array $body = null, int $admin = 1): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/v1/dnssec')
            ->withHeader('Authorization', 'Bearer ' . $token);
        return $app->handle($body === null ? $request : $request->withParsedBody($body));
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testGetReturnsTheSetting(): void {
        $this->assertSame(['dnssec' => ['active' => false]], self::body($this->request($this->app(), 'GET')));
    }

    public function testPatchTurnsItOnAndAuditsIt(): void {
        $response = $this->request($this->app(), 'PATCH', ['active' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['dnssec' => ['active' => true]], self::body($response));
        $this->assertSame(['active' => 1], Config::get('dnssec'));
        $this->assertSame('dnssec', R::getCell('SELECT object FROM history'));
    }

    public function testPatchWithTheCurrentValueWritesNoHistory(): void {
        $this->request($this->app(), 'PATCH', ['active' => false]);

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    /** @return array<string, array{0: array}> */
    public static function refused(): array {
        return [
            'missing active' => [[]],
            'not a boolean'  => [['active' => 'yes']],
            'unknown field'  => [['active' => true, 'algorithm' => 10]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testPatchRefusesAnInvalidBody(array $body): void {
        $response = $this->request($this->app(), 'PATCH', $body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['active' => 0], Config::get('dnssec'));
    }

    public function testRequiresAnAdmin(): void {
        $app = $this->app();

        $this->assertSame(403, $this->request($app, 'GET', null, 0)->getStatusCode());
        $this->assertSame(403, $this->request($app, 'PATCH', ['active' => true], 0)->getStatusCode());
        $this->assertSame(['active' => 0], Config::get('dnssec'));
    }
}
