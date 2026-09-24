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
 * A user's own email-notification switch and filter (Notifier): theirs,
 * their manager's, or an admin's to change.
 */
final class UserNotificationsTest extends TestCase
{
    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['manager', 2],
        3 => ['manager', 3],
        4 => ['user', 2],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'          => 'test-signing-key-for-this-suite-only',
            'allowed_origins'  => [],
            'allowed_headers'  => [],
            'allowed_methods'  => [],
            'safe_networks'    => ['127.0.0.1/32'],
            'trusted_proxies'  => [],
            'login_ratelimit'  => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/user_settings.php';
        return $app;
    }

    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 2): ResponseInterface {
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

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testANewUserHasNoNotificationFilterYet(): void {
        $response = $this->call($this->app(), 'GET', '/v1/users/2/notifications');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['enabled' => false, 'message_types' => [], 'fulltext' => ''], self::json($response)['notifications']);
    }

    public function testAUserSetsTheirOwnFilter(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/2/notifications', [
            'message_types' => ['dnsWarningMsgData'], 'fulltext' => 'expired',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['dnsWarningMsgData'], self::json($response)['notifications']['message_types']);
        $this->assertSame('expired', self::json($response)['notifications']['fulltext']);
    }

    public function testAUserCannotSetSomeoneElsesFilter(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'GET', '/v1/users/3/notifications')->getStatusCode());
        $this->assertSame(403, $this->call($app, 'PATCH', '/v1/users/3/notifications', ['fulltext' => 'x'])->getStatusCode());
    }

    public function testAnAdminMaySetAnyonesFilter(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/3/notifications', ['fulltext' => 'x'], 1);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUnknownTypeIsRejected(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/2/notifications', ['message_types' => ['bogus']]);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNotificationsUnknownUserIsNotFound(): void {
        $this->assertSame(404, $this->call($this->app(), 'GET', '/v1/users/99/notifications', [], 1)->getStatusCode());
    }

    public function testANotificationsChangeIsRecordedInTheHistory(): void {
        $app = $this->app();

        $this->call($app, 'PATCH', '/v1/users/2/notifications', ['fulltext' => 'expired']);

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'users' AND object_id = 2 AND action = 'update'"));
    }

    public function testAManagerMaySetTheirOwnResellersUsersFilter(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/4/notifications', ['enabled' => true], 2);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(self::json($response)['notifications']['enabled']);
    }

    public function testAManagerCannotReachAnotherResellersUser(): void {
        $this->assertSame(403, $this->call($this->app(), 'PATCH', '/v1/users/3/notifications', ['enabled' => true], 2)->getStatusCode());
    }

    public function testAPlainUserCannotReachTheirManager(): void {
        $this->assertSame(403, $this->call($this->app(), 'GET', '/v1/users/2/notifications', [], 4)->getStatusCode());
    }
}
