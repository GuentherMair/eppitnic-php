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
 * `users.max_idle_time` (minutes) ends a session that made no request for
 * that long; every JWT request refreshes `last_activity`, at most once a
 * minute. Fixed API tokens and remote auth have no session to expire.
 */
final class IdleTimeoutTest extends TestCase
{
    private const PASSWORD = 'Current-Passw0rd!';

    protected function setUp(): void {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['127.0.0.1/32'],
            'trusted_proxies' => [],
            'remote_auth'     => ['enabled' => false, 'header' => null],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
            'epp' => ['server' => 'https://epp.example', 'username' => 'REG', 'password' => 'secret-pw',
                      'port' => null, 'interface' => '', 'lang' => 'en', 'cl_trid_prefix' => 'T',
                      'server_deleted' => '', 'lastPasswordUpdate' => 0],
        ]);
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensure(1, 'user', 1, 'plain');
        R::exec('UPDATE users SET password = ?, max_idle_time = 30 WHERE id = 1', [
            password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function call(string $method, string $path, array $body = [], ?string $token = null): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';

        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
            ->withParsedBody($body);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $app->handle($request);
    }

    private function login(): string {
        $response = $this->call('POST', '/v1/users/authenticate', ['username' => 'plain', 'password' => self::PASSWORD]);
        $this->assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true)['token'];
    }

    private function idleFor(int $seconds): void {
        R::exec('UPDATE users SET last_activity = ? WHERE id = 1', [gmdate('Y-m-d H:i:s', time() - $seconds)]);
    }

    private function lastActivity(): ?int {
        $value = R::getCell('SELECT last_activity FROM users WHERE id = 1');
        return $value === null ? null : strtotime($value . ' UTC');
    }

    public function testLoggingInStartsTheClock(): void {
        $this->login();

        $this->assertEqualsWithDelta(time(), $this->lastActivity(), 5);
    }

    public function testARequestWithinTheLimitRefreshesTheClockOnceAMinute(): void {
        $token = $this->login();

        $this->idleFor(20 * 60);
        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());
        $this->assertEqualsWithDelta(time(), $this->lastActivity(), 5);

        $this->idleFor(20);
        $this->call('GET', '/v1/users/me', [], $token);
        $this->assertEqualsWithDelta(time() - 20, $this->lastActivity(), 5, 'written again within the minute');
    }

    public function testASessionIdlePastTheLimitIsRefused(): void {
        $token = $this->login();
        $this->idleFor(31 * 60);

        $response = $this->call('GET', '/v1/users/me', [], $token);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Session expired after inactivity', json_decode((string) $response->getBody(), true)['error']);
        $this->assertEqualsWithDelta(time() - 31 * 60, $this->lastActivity(), 5, 'a refused request must not refresh');
        $this->assertSame(401, $this->call('GET', '/v1/users/renew-token', [], $token)->getStatusCode());
    }

    /** @return array<string, array{0: ?int}> */
    public static function noLimit(): array {
        return ['null' => [null], 'zero' => [0]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noLimit')]
    public function testNoLimitMeansNoIdleExpiry(?int $maxIdle): void {
        R::exec('UPDATE users SET max_idle_time = ? WHERE id = 1', [$maxIdle]);
        $token = $this->login();
        $this->idleFor(30 * 24 * 3600);

        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());
    }

    public function testAFixedApiTokenNeverIdlesOut(): void {
        // SQLite has no UNIX_TIMESTAMP(), which the token lookup calls
        @R::getPDO()->sqliteCreateFunction('UNIX_TIMESTAMP', 'time', 0);
        R::exec('UPDATE users SET api_token = ? WHERE id = 1', [hash('sha256', 'automation-token')]);
        $this->idleFor(30 * 24 * 3600);

        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], 'automation-token')->getStatusCode());
        $this->assertEqualsWithDelta(time() - 30 * 24 * 3600, $this->lastActivity(), 5, 'a fixed token is not a session');
    }
}
