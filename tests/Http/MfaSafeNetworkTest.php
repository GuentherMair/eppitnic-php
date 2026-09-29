<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `totp_verified` means a code was checked; a login from a safe network
 * skips the code, so its token passes the MFA gate only from such a network.
 */
final class MfaSafeNetworkTest extends TestCase
{
    private const PASSWORD = 'Current-Passw0rd!';
    private const OUTSIDE  = '198.51.100.7';
    private const SAFE     = '10.1.2.3';

    private string $secret;

    protected function setUp(): void {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['10.0.0.0/8'],
            'trusted_proxies' => [],
            'remote_auth'     => ['enabled' => false, 'header' => null],
            'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
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
        TestAccounts::ensure(1, 'admin', 1, 'admin');
        $this->secret = TOTP::generate(null, 20)->getSecret();
        R::exec('UPDATE users SET password = ?, totp_secret = ? WHERE id = 1', [
            password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), $this->secret,
        ]);
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
        Config::reset();
        parent::tearDown();
    }

    private function call(string $method, string $path, string $ip, array $body = [], ?string $token = null): ResponseInterface {
        $_SERVER['REMOTE_ADDR'] = $ip;        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';

        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
            ->withParsedBody($body);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> the login answer, token included */
    private function login(string $ip, bool $withCode): array {
        $body = ['username' => 'admin', 'password' => self::PASSWORD];
        if ($withCode) {
            $body['totp'] = TOTP::createFromSecret($this->secret)->now();
        }
        $response = $this->call('POST', '/v1/users/authenticate', $ip, $body);
        $this->assertSame(200, $response->getStatusCode());
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function credentials(string $token, string $ip): ResponseInterface {
        return $this->call('GET', '/v1/session/epp/credentials', $ip, [], $token);
    }

    public function testASafeNetworkLoginTokenIsNotMfaVerified(): void {
        $json = $this->login(self::SAFE, false);

        $this->assertTrue($json['has_totp']);
        $this->assertFalse($json['needs_totp']);
        $this->assertFalse($json['totp_verified']);
    }

    public function testASafeNetworkTokenPassesTheGateOnlyFromASafeNetwork(): void {
        $token = $this->login(self::SAFE, false)['token'];

        $this->assertSame(200, $this->credentials($token, self::SAFE)->getStatusCode());

        $response = $this->credentials($token, self::OUTSIDE);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA verification required', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testACodeLoginWorksFromAnywhere(): void {
        $json = $this->login(self::OUTSIDE, true);

        $this->assertTrue($json['totp_verified']);
        $this->assertSame(200, $this->credentials($json['token'], self::OUTSIDE)->getStatusCode());
        $this->assertSame(200, $this->credentials($json['token'], self::SAFE)->getStatusCode());
    }

    public function testTotpEnrolledAfterLoginNoLongerPassesAsMfaFree(): void {
        R::exec('UPDATE users SET totp_secret = NULL WHERE id = 1');
        $json = $this->login(self::OUTSIDE, false);
        $this->assertFalse($json['has_totp']);
        $this->assertSame(200, $this->credentials($json['token'], self::OUTSIDE)->getStatusCode());

        R::exec('UPDATE users SET totp_secret = ? WHERE id = 1', [$this->secret]);

        $this->assertSame(403, $this->credentials($json['token'], self::OUTSIDE)->getStatusCode());
    }

    public function testRenewTokenKeepsTheVerification(): void {
        $verified = $this->login(self::OUTSIDE, true)['token'];
        $safe = $this->login(self::SAFE, false)['token'];

        $renewedVerified = json_decode((string) $this->call('GET', '/v1/users/renew-token', self::OUTSIDE, [], $verified)->getBody(), true);
        $renewedSafe = json_decode((string) $this->call('GET', '/v1/users/renew-token', self::SAFE, [], $safe)->getBody(), true);

        $this->assertTrue($renewedVerified['totp_verified']);
        $this->assertFalse($renewedSafe['totp_verified']);
    }
}
