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
 * Any logged-in user may read the user list, so an address or a quota is for
 * an admin alone. The admin's edit dialog needs them to show what it changes.
 */
final class UserReadFieldsTest extends TestCase
{
    private const PRIVATE_FIELDS = ['description', 'email', 'max_operations'];

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
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, description TEXT, username TEXT, password TEXT,
                 email TEXT, max_operations INTEGER DEFAULT 0, active INTEGER DEFAULT 1, admin INTEGER DEFAULT 0,
                 totp_secret TEXT, max_token_age INTEGER, max_idle_time INTEGER, debug INTEGER DEFAULT 0)');
        R::exec("INSERT INTO users (id, username, description, email, max_operations, admin)
                 VALUES (1, 'admin', 'The admin', 'admin@example.it', 0, 1),
                        (2, 'reseller', 'A reseller', 'reseller@example.it', 5, 0)");

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function get(\Slim\App $app, string $path, int $as): ResponseInterface {
        $token = Auth::issueToken([
            'id' => $as, 'username' => 'someone', 'admin' => $as === 1 ? 1 : 0,
            'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function users(ResponseInterface $response): array {
        return (array) (json_decode((string) $response->getBody(), true)['users'] ?? []);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testAnAdminSeesWhatTheEditDialogNeeds(): void {
        $rows = self::users($this->get($this->app(), '/v1/users', 1));

        $reseller = array_values(array_filter($rows, fn($row) => $row['username'] === 'reseller'))[0];
        $this->assertSame('A reseller', $reseller['description']);
        $this->assertSame('reseller@example.it', $reseller['email']);
        $this->assertSame(5, (int) $reseller['max_operations']);
    }

    public function testTheSameForOneUserById(): void {
        $rows = self::users($this->get($this->app(), '/v1/users/2', 1));

        $this->assertSame('reseller@example.it', $rows[0]['email']);
    }

    public function testAResellerDoesNotSeeAnyonesAddressOrQuota(): void {
        $app = $this->app();

        foreach (['/v1/users', '/v1/users/1'] as $path) {
            foreach (self::users($this->get($app, $path, 2)) as $row) {
                foreach (self::PRIVATE_FIELDS as $field) {
                    $this->assertArrayNotHasKey($field, $row, "{$path} handed a reseller {$field}");
                }
            }
        }
    }

    public function testEveryoneStillSeesWhoExists(): void {
        $rows = self::users($this->get($this->app(), '/v1/users', 2));

        $this->assertSame(['admin', 'reseller'], array_column($rows, 'username'));
    }
}
