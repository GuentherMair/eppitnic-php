<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use Eppitnic\Tests\Support\TestAccounts;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * POST /v1/domains/{name}/status stores the status the registry reports after
 * the change, not one computed locally from the status before it.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainStatusRouteTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'allowed_origins' => [], 'allowed_headers' => [], 'allowed_methods' => [],
            'safe_networks'   => ['127.0.0.1/32'], 'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 cr_date TEXT, ex_date TEXT, dnssec TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, registrant, status)
                 VALUES ('example-one.it', 2, 'REGI1234REGI5678', ?)", [serialize(['clientHold', 'inactive'])]);

        $held = str_replace('<domain:status s="ok"/>',
            '<domain:status s="clientHold"/><domain:status s="inactive"/>', CommandCatalog::DOMAIN_INFO_RESPONSE);

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // login
        $this->sent->queue($held);                                // fetch
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // update
        $this->sent->queue(CommandCatalog::DOMAIN_INFO_RESPONSE); // fetch again: ok
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // logout
        Client::useTransportFactory(fn() => $this->sent);
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function post(array $body): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';

        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/domains/example-one.it/status')
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    public function testRemovingClientHoldStoresTheStatusTheRegistryNowReports(): void {
        $response = $this->post(['state' => 'clientHold', 'action' => 'rem']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stored = unserialize(R::getCell("SELECT status FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(['ok'], $stored, 'no inactive left behind');
        $this->assertSame($stored, json_decode((string) $response->getBody(), true)['domain']['status']);
    }
}
