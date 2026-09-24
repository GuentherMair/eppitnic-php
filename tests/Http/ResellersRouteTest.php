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
 * GET/POST/PATCH /v1/resellers: who may see and change reseller rows, and the
 * validation ResellerService enforces (ResellerServiceTest covers that layer
 * in depth; this is the HTTP shape and the scoping around it).
 */
final class ResellersRouteTest extends TestCase
{
    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['manager', 2],
        3 => ['user', 3],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['127.0.0.1/32'],
            'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'contacts', 'domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensureReseller(3, 'Three');
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/resellers.php';
        return $app;
    }

    /**
     * @param int $as the user making the request (see ACCOUNTS)
     */
    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 1): ResponseInterface {
        [$role, $reseller] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'username' => 'someone', 'role' => $role, 'reseller_id' => $reseller,
            'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function json(ResponseInterface $r): array {
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    private static function error(ResponseInterface $r): string {
        return (string) (self::json($r)['error'] ?? '');
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // GET /v1/resellers
    // ---------------------------------------------------------------

    public function testAnAdminListsEveryReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers', [], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, self::json($response)['resellers']);
    }

    public function testAManagerListsOnlyTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers', [], 2);

        $resellers = self::json($response)['resellers'];
        $this->assertCount(1, $resellers);
        $this->assertSame(2, $resellers[0]['id']);
    }

    public function testAPlainUserListsOnlyTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers', [], 3);

        $resellers = self::json($response)['resellers'];
        $this->assertCount(1, $resellers);
        $this->assertSame(3, $resellers[0]['id']);
    }

    // ---------------------------------------------------------------
    // GET /v1/resellers/{id}
    // ---------------------------------------------------------------

    public function testAnAdminReadsAnyReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers/2', [], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Two', self::json($response)['reseller']['name']);
    }

    public function testAManagerReadsTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers/2', [], 2);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAManagerCannotReadAnotherReseller(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers/3', [], 2);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnUnknownResellerIsNotFound(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers/99', [], 1);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /v1/resellers
    // ---------------------------------------------------------------

    public function testAnAdminCreatesAReseller(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers', ['name' => 'Acme', 'max_operations' => 5], 1);

        $this->assertSame(201, $response->getStatusCode());
        $reseller = self::json($response)['reseller'];
        $this->assertSame('Acme', $reseller['name']);
        $this->assertSame(5, $reseller['max_operations']);
        $this->assertSame(1, $reseller['active']);
    }

    public function testCreatingWithoutMaxOperationsDefaultsToUnlimited(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers', ['name' => 'Acme'], 1);

        $this->assertSame(0, self::json($response)['reseller']['max_operations']);
    }

    public function testCreatingWithABlankNameIsRefused(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers', ['name' => ''], 1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreatingWithADuplicateNameIsRefused(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers', ['name' => 'two'], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('already exists', self::error($response));
    }

    public function testAManagerCannotCreateAReseller(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers', ['name' => 'Acme'], 2);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PATCH /v1/resellers/{id}
    // ---------------------------------------------------------------

    public function testAnAdminChangesAResellersName(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/resellers/2', ['name' => 'Renamed'], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Renamed', self::json($response)['reseller']['name']);
    }

    public function testAnAdminDeactivatesAReseller(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/resellers/2', ['active' => false], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, self::json($response)['reseller']['active']);
    }

    public function testResellerOneCannotBeDeactivated(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/resellers/1', ['active' => false], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('registrar itself', self::error($response));
        $this->assertSame(1, (int) R::getCell('SELECT active FROM resellers WHERE id = 1'));
    }

    public function testPatchingAnUnknownResellerIsNotFound(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/resellers/99', ['name' => 'x'], 1);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAManagerCannotPatchAReseller(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/resellers/2', ['name' => 'x'], 2);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAChangeIsRecordedInHistory(): void {
        $app = $this->app();

        $this->call($app, 'PATCH', '/v1/resellers/2', ['max_operations' => 9], 1);

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'resellers' AND object_id = 2 AND action = 'update'"));
    }
}
