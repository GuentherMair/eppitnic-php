<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /v1/session/epp/credentials returns the shared registry password, which
 * makes its authorization the whole feature rather than a detail of it.
 *
 * The password is rotated automatically, so after a run of `poll process` this
 * is the only way short of a SQL client to learn the current credential --
 * which is exactly why the guard on it has to be exercised rather than
 * assumed.
 */
final class EppCredentialsTest extends TestCase
{
    private const PATH = '/v1/session/epp/credentials';

    private const SETTINGS = [
        'jwt_psk' => 'test-signing-key-for-this-suite-only',
        'region'  => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
        'epp'     => [
            'server'   => 'https://epp.pubtest.nic.it',
            'username' => 'TEST-REG',
            'password' => 'a-known-test-password',
            'port'     => null,
            'interface' => '',
            'lang'     => 'en',
            'cl_trid_prefix' => 'TEST',
            'server_deleted' => '',
            'lastPasswordUpdate' => 0,
        ],
    ];

    /**
     * @param array<string, mixed> $epp overrides merged over the base epp setting
     */
    private function app(array $epp = []): \Slim\App {
        Config::loadForTesting(['epp' => $epp + self::SETTINGS['epp']] + self::SETTINGS);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $claims what the caller's token asserts
     */
    private function get(\Slim\App $app, ?array $claims = null): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . self::PATH);

        if ($claims !== null) {
            $token = Auth::issueToken($claims + ['id' => 1, 'username' => 'someone', 'max_token_age' => 60])['token'];
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testAnonymousIsRefused(): void {
        $response = $this->get($this->app());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('a-known-test-password', (string) $response->getBody());
    }

    public function testANonAdminIsRefused(): void {
        $response = $this->get($this->app(), ['admin' => 0, 'has_totp' => false]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('a-known-test-password', (string) $response->getBody());
    }

    /**
     * An admin who has TOTP enabled but has not completed it for this session
     * is refused too -- otherwise enabling MFA would weaken this route, since
     * a stolen first-factor token would still reach it.
     */
    public function testAnAdminWithUnfinishedMfaIsRefused(): void {
        $response = $this->get($this->app(), ['admin' => 1, 'has_totp' => true, 'totp_verified' => false]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('a-known-test-password', (string) $response->getBody());
    }

    public function testAnAdminGetsTheCredentials(): void {
        $response = $this->get($this->app(), ['admin' => 1, 'has_totp' => false]);

        $this->assertSame(200, $response->getStatusCode());

        $credentials = self::body($response)['credentials'];
        $this->assertSame('a-known-test-password', $credentials['password']);
        $this->assertSame('TEST-REG', $credentials['username']);
        $this->assertSame('https://epp.pubtest.nic.it', $credentials['server']);
    }

    /**
     * An admin who completed TOTP gets through, so MFA is a gate rather than a
     * wall.
     */
    public function testAnAdminWhoCompletedMfaGetsThrough(): void {
        $response = $this->get($this->app(), ['admin' => 1, 'has_totp' => true, 'totp_verified' => true]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A rotation that did not finish leaves two candidate passwords, and which
     * one the registry holds is a question only the registry can answer. Both
     * are returned, because withholding either leaves the operator locked out
     * in the one case where they most need to get in.
     */
    public function testAnUnfinishedRotationReturnsBothPasswords(): void {
        $app = $this->app(['pendingPassword' => 'the-candidate']);

        $credentials = self::body($this->get($app, ['admin' => 1, 'has_totp' => false]))['credentials'];

        $this->assertSame('a-known-test-password', $credentials['password']);
        $this->assertSame('the-candidate', $credentials['pending_password']);
        $this->assertStringContainsString('doctor epp-password', $credentials['note']);
    }

    /**
     * A finished rotation says nothing about a candidate, so a UI does not have
     * to test for an empty string to know whether to warn.
     */
    public function testNoPendingKeyWhenThereIsNoRotation(): void {
        $credentials = self::body($this->get($this->app(), ['admin' => 1, 'has_totp' => false]))['credentials'];

        $this->assertArrayNotHasKey('pending_password', $credentials);
        $this->assertArrayNotHasKey('note', $credentials);
    }

    public function testAnUnconfiguredInstallationGets404(): void {
        $response = $this->get($this->app(['password' => '']), ['admin' => 1, 'has_totp' => false]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    /**
     * The settings endpoint next door must keep saying only whether a password
     * exists. It is what a settings screen loads, and this test is what stops
     * someone "simplifying" the two into one response.
     */
    public function testTheSettingsEndpointStillWithholdsThePassword(): void {
        $app = $this->app();
        $token = Auth::issueToken(['id' => 1, 'admin' => 1, 'has_totp' => false, 'max_token_age' => 60])['token'];
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/v1/session/epp')
            ->withHeader('Authorization', "Bearer {$token}");

        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('a-known-test-password', (string) $response->getBody());
        $this->assertTrue(self::body($response)['epp']['password_set']);
    }
}
