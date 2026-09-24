<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `PATCH /v1/session/epp` and `GET /v1/session/epp/interfaces` -- the API
 * surface over EppSettings, the same class `config epp-set`/`config
 * epp-server` use (see EppSettingsTest for that shared layer's own
 * validation coverage).
 */
final class EppSettingsRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk' => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'  => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
        'epp'     => [
            'server'             => 'https://epp.pubtest.nic.it',
            'server_deleted'     => 'https://epp-deleted.nic.it',
            'username'           => 'TEST-REG',
            'password'           => 'a-known-test-password',
            'port'               => null,
            'interface'          => '',
            'lang'               => 'en',
            'cl_trid_prefix'     => 'TEST',
            'lastPasswordUpdate' => 0,
        ],
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
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function patch(\Slim\App $app, array $body, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('PATCH', 'http://localhost/v1/session/epp')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
                ->withParsedBody($body)
        );
    }

    private function getInterfaces(\Slim\App $app, ?int $admin = 1): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/session/epp/interfaces');
        if ($admin !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token($admin));
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

    public function testPatchUpdatesAFieldAndReturnsTheFullPublicShape(): void {
        $app = $this->app();
        $response = $this->patch($app, ['lang' => 'it']);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('it', $body['epp']['lang']);
        $this->assertSame('it', Config::get('epp')['lang']);
        $this->assertArrayNotHasKey('password', $body['epp'], 'the password must never ride along here');
        $this->assertTrue($body['epp']['password_set']);
    }

    public function testPatchPreservesFieldsNotBeingChanged(): void {
        $app = $this->app();
        $this->patch($app, ['lang' => 'it']);

        $this->assertSame('TEST-REG', Config::get('epp')['username']);
        $this->assertSame('a-known-test-password', Config::get('epp')['password']);
    }

    public function testPatchWritesAHistoryRow(): void {
        $app = $this->app();
        $this->patch($app, ['cl_trid_prefix' => 'ACME']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'epp'");
        $this->assertNotEmpty($row);
        $this->assertSame('7', (string) $row['user_id']);
        $this->assertStringContainsString('cl_trid_prefix', $row['data']);
    }

    public function testPatchRejectsAnUnknownField(): void {
        $app = $this->app();
        $response = $this->patch($app, ['password' => 'whatever']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPatchRejectsAnInvalidValue(): void {
        $app = $this->app();
        $response = $this->patch($app, ['lang' => 'fr']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('en', Config::get('epp')['lang']);
    }

    public function testPatchRejectsUnsettingARequiredField(): void {
        $app = $this->app();
        $response = $this->patch($app, ['server' => null]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPatchRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->patch($app, ['lang' => 'it'], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('en', Config::get('epp')['lang']);
    }

    public function testInterfacesRequiresAdmin(): void {
        $app = $this->app();

        $this->assertSame(401, $this->getInterfaces($app, null)->getStatusCode());
        $this->assertSame(403, $this->getInterfaces($app, 0)->getStatusCode());
    }

    public function testCreditRequiresAdmin(): void {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/session/credit')
            ->withHeader('Authorization', 'Bearer ' . $this->token(0));

        $this->assertSame(403, $app->handle($request)->getStatusCode(), 'the registrar\'s balance, not a reseller\'s');
    }

    public function testInterfacesReturnsAnArrayWithoutLoopback(): void {
        $app = $this->app();
        $response = $this->getInterfaces($app);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($body['interfaces']);
        foreach ($body['interfaces'] as $address) {
            $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $address);
            $this->assertStringStartsNotWith('127.', $address);
        }
    }
}
