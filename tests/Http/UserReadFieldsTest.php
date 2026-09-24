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
 * Any logged-in user may read the user list, so an address or a quota is for
 * an admin alone. The admin's edit dialog needs them to show what it changes.
 */
final class UserReadFieldsTest extends TestCase
{
    private const PRIVATE_FIELDS = ['description', 'email'];

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
        R::exec('DROP TABLE IF EXISTS resellers');
        TestAccounts::ensureReseller(2, 'A reseller');
        TestAccounts::ensure(1, 'admin', 1, 'admin');
        TestAccounts::ensure(2, 'manager', 2, 'reseller');
        TestAccounts::ensure(3, 'user', 2, 'colleague');
        TestAccounts::ensure(4, 'manager', 3, 'elsewhere');
        R::exec("UPDATE users SET description = 'The admin', email = 'admin@example.it' WHERE id = 1");
        R::exec("UPDATE users SET description = 'A reseller', email = 'reseller@example.it' WHERE id = 2");
        R::exec("UPDATE users SET description = 'A colleague', email = 'colleague@example.it' WHERE id = 3");

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function get(\Slim\App $app, string $path, int $as): ResponseInterface {
        $account = R::getRow('SELECT role, reseller_id FROM users WHERE id = ?', [$as]);
        $token = TestAccounts::issueToken([
            'id' => $as, 'username' => 'someone', 'role' => $account['role'], 'reseller_id' => (int) $account['reseller_id'],
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
        $this->assertSame('manager', $reseller['role']);
        $this->assertSame(2, (int) $reseller['reseller_id']);
        $this->assertSame('A reseller', $reseller['reseller_name']);
        $this->assertCount(4, $rows, 'every reseller\'s users');
    }

    public function testAnAdminMayListOneResellersUsers(): void {
        $rows = self::users($this->get($this->app(), '/v1/users?reseller_id=2', 1));

        $this->assertSame(['reseller', 'colleague'], array_column($rows, 'username'));
    }

    public function testTheSameForOneUserById(): void {
        $rows = self::users($this->get($this->app(), '/v1/users/2', 1));

        $this->assertSame('reseller@example.it', $rows[0]['email']);
    }

    public function testAManagerSeesTheirResellersUsersWithTheirAddresses(): void {
        $rows = self::users($this->get($this->app(), '/v1/users', 2));

        $this->assertSame(['reseller', 'colleague'], array_column($rows, 'username'));
        $this->assertSame('colleague@example.it', $rows[1]['email']);
    }

    public function testAManagerCannotReadAnotherResellersUser(): void {
        $app = $this->app();

        $this->assertSame([], self::users($this->get($app, '/v1/users/4', 2)));
        $this->assertSame([], self::users($this->get($app, '/v1/users/1', 2)));
    }

    public function testAPlainUserSeesOnlyThemselvesWithoutAddresses(): void {
        $app = $this->app();

        $rows = self::users($this->get($app, '/v1/users', 3));
        $this->assertSame(['colleague'], array_column($rows, 'username'));
        foreach (self::PRIVATE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $rows[0]);
        }
        $this->assertSame([], self::users($this->get($app, '/v1/users/2', 3)));
    }
}
