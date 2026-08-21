<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\SetupApp;
use Eppitnic\Setup\ConfigFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * SetupApp -- what public/index.php serves in place of the real application
 * while config/config.php doesn't exist -- dispatched exactly the way
 * ErrorResponseTest dispatches the configured application, so a regression in
 * either fails the same kind of test.
 *
 * Deliberately builds with no Config::loadForTesting() at all, unlike
 * ErrorResponseTest: that absence is itself part of what's being proven here.
 * SetupApp and its routes must need no database to answer, so every
 * ConfigFile::exists() check happens lazily, at request time inside the
 * closure, never at include time -- which is what lets one shared $app
 * (built once, below) be pointed at a different ConfigFile path per test.
 */
final class SetupRouteTest extends TestCase
{
    /**
     * src/Api/Routes/setup.php declares a global function at include time,
     * so -- same reason as ErrorResponseTest's $app -- SetupApp::build() may
     * only run once per process; a second call would silently return an app
     * with no routes on it (require_once's second call is a no-op against a
     * *new* Slim instance).
     */
    private static ?App $app = null;

    private static function app(): App {
        return self::$app ??= (new SetupApp())->build();
    }

    protected function tearDown(): void {
        ConfigFile::usePath(null);
    }

    private function request(string $method, string $path, ?array $body = null): \Psr\Http\Message\ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}");
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return self::app()->handle($request);
    }

    /**
     * POST /v1/setup with an open installer.
     *
     * @param array<string, string> $input
     * @return array{status: int, error: string} the status and whatever it
     *         complained about -- these cases never get as far as a success
     */
    private function install(array $input): array {
        $this->useNonexistentConfigPath();
        $response = $this->request('POST', '/v1/setup', $input);
        $body = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'error' => (string) ($body['error'] ?? '')];
    }

    private function useNonexistentConfigPath(): void {
        ConfigFile::usePath(sys_get_temp_dir() . '/eppitnic-setuproute-test-' . bin2hex(random_bytes(4)) . '.php');
    }

    public function testGetSetupListsTheRequirementsWhenUnconfigured(): void {
        $this->useNonexistentConfigPath();

        $response = $this->request('GET', '/v1/setup');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['required']);
        $this->assertNotEmpty($body['fields']);
        $this->assertSame('db_type', $body['fields'][0]['name']);
    }

    public function testPostVerifyWithAMissingFieldIs400NotACrash(): void {
        $this->useNonexistentConfigPath();

        $response = $this->request('POST', '/v1/setup/verify', ['db_name' => 'x']);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNotSame('', $body['error']);
    }

    public function testRootServesTheBundledInstallerPage(): void {
        $this->useNonexistentConfigPath();

        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<title>EPP IT-NIC Setup</title>', (string) $response->getBody());
    }

    public function testCorsIsPermissive(): void {
        $this->useNonexistentConfigPath();

        $response = $this->request('GET', '/v1/setup');

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public static function routeProvider(): array {
        return [
            'GET /v1/setup'         => ['GET', '/v1/setup'],
            'POST /v1/setup/verify' => ['POST', '/v1/setup/verify'],
            'POST /v1/setup'        => ['POST', '/v1/setup'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testEveryRouteIs404OnceConfigured(string $method, string $path): void {
        $path2 = sys_get_temp_dir() . '/eppitnic-setuproute-test-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path2, "<?php\n");
        ConfigFile::usePath($path2);

        try {
            $response = $this->request($method, $path, $method === 'GET' ? null : []);
            $this->assertSame(404, $response->getStatusCode());
        } finally {
            @unlink($path2);
        }
    }

    // ---------------------------------------------------------------
    // the admin password
    // ---------------------------------------------------------------

    /**
     * The form checks this too, but the endpoint is reachable with curl and
     * the account it creates is the one administrator the installation starts
     * with -- so the rule has to hold here, not only in a browser.
     */
    public function testAWeakAdminPasswordIsRefused(): void {
        $body = $this->install([
            'db_name' => 'x', 'db_user' => 'x',
            'admin_username' => 'admin', 'admin_password' => 'short',
        ]);

        $this->assertSame(400, $body['status']);
        $this->assertStringContainsString('password needs', $body['error']);
    }

    /**
     * Refused before the database is touched: a password that will not be
     * accepted should not first cost a connection attempt to somebody else's
     * host, and the message must not depend on that attempt's outcome.
     */
    public function testTheWeakPasswordIsCaughtBeforeAnyConnection(): void {
        $body = $this->install([
            'db_name' => 'x', 'db_user' => 'x', 'db_host' => '203.0.113.1',
            'admin_username' => 'admin', 'admin_password' => 'short',
        ]);

        $this->assertStringContainsString('password needs', $body['error']);
        $this->assertStringNotContainsString('connect', strtolower($body['error']));
    }

    /**
     * A slip-guard for a person typing into a form. Only checked when the
     * caller sent one -- an API client should not be made to repeat itself.
     */
    public function testMismatchedConfirmationIsRefused(): void {
        $body = $this->install([
            'db_name' => 'x', 'db_user' => 'x',
            'admin_username' => 'admin',
            'admin_password' => 'Correct-Horse-42!',
            'admin_password_confirm' => 'Correct-Horse-43!',
        ]);

        $this->assertSame(400, $body['status']);
        $this->assertStringContainsString('do not match', $body['error']);
    }

    /**
     * The form has to be able to state the rule before anybody types into it,
     * and from the same list install() will judge the answer against.
     */
    public function testTheFieldListCarriesThePasswordPolicy(): void {
        $this->useNonexistentConfigPath();
        $body = json_decode((string) $this->request('GET', '/v1/setup')->getBody(), true);

        $this->assertArrayHasKey('password_policy', $body);
        $this->assertSame(
            ['length', 'lower', 'upper', 'number', 'special'],
            array_column($body['password_policy'], 'key')
        );
    }
}
