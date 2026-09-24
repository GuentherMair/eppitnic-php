<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /v1/session/epp/credentials returns the shared registry password, which
 * makes its authorization the whole feature. Rotation is automatic, so this is
 * the only way short of a SQL client to learn the current credential.
 */
final class EppCredentialsTest extends TestCase
{
    private const PATH = '/v1/session/epp/credentials';

    private const SETTINGS = [
        'jwt_psk' => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
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
     * @param array<string, mixed> $epp overrides merged over the base epp
     *                       setting
     */
    private function app(array $epp = []): \Slim\App {
        Config::loadForTesting(['epp' => $epp + self::SETTINGS['epp']] + self::SETTINGS);

        // the endpoint writes a security row before answering, so it needs a
        // history table to write to
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

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
    private function get(\Slim\App $app, ?array $claims = null, array $headers = []): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . self::PATH);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($claims !== null) {
            $token = TestAccounts::issueToken($claims + ['id' => 1, 'username' => 'someone', 'max_token_age' => 60])['token'];
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $app->handle($request);
    }

    /**
     * @return array<int, array<string, mixed>> the security rows written,
     *                       decoded
     */
    private static function securityRows(): array {
        $rows = R::getAll("SELECT * FROM history WHERE object = 'security' ORDER BY id");
        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $rows;
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
     * A rotation that did not finish leaves two candidates, and only the
     * registry knows which it holds. Both are returned: withholding either
     * locks the operator out in the one case where they most need in.
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
     * A retrieval is recorded before the credential is handed over, with where
     * it was taken from.
     */
    public function testARetrievalIsRecorded(): void {
        $app = $this->app();
        $this->get($app, ['admin' => 1, 'has_totp' => false], ['User-Agent' => 'some-client/1.0']);

        $rows = self::securityRows();
        $this->assertCount(1, $rows, 'the retrieval was not recorded');

        $this->assertSame('secread', $rows[0]['action'], "a retrieval is not a create, update or delete");
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame(1, (int) $rows[0]['object_id'], 'object_id should be the acting user');
        $this->assertSame('epp_credentials_retrieved', $rows[0]['data']['event']);
        $this->assertSame('some-client/1.0', $rows[0]['data']['headers']['User-Agent']);
        $this->assertArrayHasKey('ip', $rows[0]['data']);
    }

    /**
     * The point of the whole exercise: the log says a credential was taken, not
     * what it was. The log is read by more people, and far more casually, than
     * the thing it is about.
     */
    public function testThePasswordIsNeverWrittenToTheLog(): void {
        $app = $this->app(['pendingPassword' => 'the-candidate']);
        $this->get($app, ['admin' => 1, 'has_totp' => false]);

        $written = (string) R::getCell("SELECT data FROM history WHERE object = 'security'");

        $this->assertStringNotContainsString('a-known-test-password', $written);
        $this->assertStringNotContainsString('the-candidate', $written);
        $this->assertTrue(self::securityRows()[0]['data']['rotation_pending']);
    }

    /**
     * The Authorization header is a live bearer token: writing it here puts a
     * working credential in a table read more casually than the thing it is
     * about, and anyone with SELECT could act as whoever was logged.
     *
     * @param string $header a header whose value is itself a credential
     */
    #[DataProvider('credentialHeaders')]
    public function testCredentialHeadersAreRedacted(string $header, string $value): void {
        $app = $this->app();
        $this->get($app, ['admin' => 1, 'has_totp' => false], [$header => $value]);

        $written = (string) R::getCell("SELECT data FROM history WHERE object = 'security'");
        $headers = self::securityRows()[0]['data']['headers'];

        $this->assertStringNotContainsString($value, $written, "{$header} was written verbatim");
        // present but redacted, so its absence from the log is not mistaken
        // for its absence from the request
        $this->assertSame('[redacted]', $headers[$header] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function credentialHeaders(): array {
        return [
            'Authorization'       => ['Authorization', 'Bearer a-stealable-token'],
            'Cookie'              => ['Cookie', 'session=a-stealable-session'],
            'Proxy-Authorization' => ['Proxy-Authorization', 'Basic a-stealable-basic'],
        ];
    }

    /**
     * A refused request records nothing: it discloses nothing, and a log that
     * fills up with unauthorized probes buries the entries that matter.
     */
    public function testARefusedRequestIsNotRecorded(): void {
        $app = $this->app();
        $this->get($app, ['admin' => 0, 'has_totp' => false]);

        $this->assertSame([], self::securityRows());
    }

    /**
     * The settings endpoint next door must keep saying only whether a password
     * exists. It is what a settings screen loads, and this test is what stops
     * someone "simplifying" the two into one response.
     */
    public function testTheSettingsEndpointStillWithholdsThePassword(): void {
        $app = $this->app();
        $token = TestAccounts::issueToken(['id' => 1, 'admin' => 1, 'has_totp' => false, 'max_token_age' => 60])['token'];
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/v1/session/epp')
            ->withHeader('Authorization', "Bearer {$token}");

        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('a-known-test-password', (string) $response->getBody());
        $this->assertTrue(self::body($response)['epp']['password_set']);
    }
}
