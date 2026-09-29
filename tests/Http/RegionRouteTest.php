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
 * `GET`/`PATCH /v1/region` -- the API surface over RegionSettings, and the
 * validation that surface relies on.
 */
final class RegionRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'          => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT'],
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
        require EPPITNIC_ROOT . '/src/Api/Routes/region.php';
        return $app;
    }

    private function request(\Slim\App $app, string $method, ?array $body = null, int $admin = 1): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/v1/region')
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

    public function testGetReturnsTheSettingAndTheTimezones(): void {
        $body = self::body($this->request($this->app(), 'GET'));

        $this->assertSame(self::SETTINGS['region'], $body['region']);
        $this->assertContains('Europe/Rome', $body['timezones']);
        $this->assertContains('America/New_York', $body['timezones']);
    }

    public function testPatchChangesOnlyTheGivenFieldsAndAuditsThem(): void {
        $app = $this->app();
        $response = $this->request($app, 'PATCH', ['timezone' => 'Europe/Berlin', 'lc_monetary' => 'de_DE.UTF-8']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['timezone' => 'Europe/Berlin', 'lc_monetary' => 'de_DE.UTF-8'],
            self::body($response)['region']
        );
        $this->assertSame('Europe/Berlin', Config::get('region')['timezone']);
        $this->assertSame('region', R::getCell("SELECT object FROM history"));
    }

    /** @return array<string, array{0: array}> */
    public static function refused(): array {
        return [
            'unknown time zone'  => [['timezone' => 'Mars/Olympus']],
            'blank time zone'    => [['timezone' => '']],
            'locale with spaces' => [['lc_monetary' => 'it IT']],
            'locale with a path' => [['lc_monetary' => '../etc']],
            'the dropped lc_time' => [['lc_time' => 'it_IT.UTF-8']],
            'unknown field'      => [['currency' => 'EUR']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testPatchRefusesAnInvalidValue(array $body): void {
        $app = $this->app();
        $response = $this->request($app, 'PATCH', $body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Europe/Rome', Config::get('region')['timezone']);
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedLocales(): array {
        return [
            'C'              => ['C'],
            'POSIX'          => ['POSIX'],
            'language only'  => ['italian'],
            'with a country' => ['it_IT'],
            'with a charset' => ['it_IT.UTF-8'],
            'with a modifier' => ['de_DE@euro'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedLocales')]
    public function testPatchAcceptsALocaleName(string $locale): void {
        $response = $this->request($this->app(), 'PATCH', ['lc_monetary' => $locale]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($locale, Config::get('region')['lc_monetary']);
    }

    public function testRequiresAnAdmin(): void {
        $app = $this->app();

        $this->assertSame(403, $this->request($app, 'GET', null, 0)->getStatusCode());
        $this->assertSame(403, $this->request($app, 'PATCH', ['timezone' => 'UTC'], 0)->getStatusCode());
        $this->assertSame('Europe/Rome', Config::get('region')['timezone']);
    }
}
