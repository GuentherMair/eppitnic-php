<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Tests\Support\FakeMailer;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `GET`/`PATCH /v1/smtp` -- the API surface over Notifier, the same class
 * `config smtp-set` uses (see NotifierTest for that shared layer's own
 * validation coverage).
 */
final class SmtpRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk' => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'  => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
        'smtp'    => [
            'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
            'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => 'a-known-test-password',
            'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
        ],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting(self::SETTINGS);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/smtp.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function get(\Slim\App $app, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/smtp')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
        );
    }

    private function patch(\Slim\App $app, array $body, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('PATCH', 'http://localhost/v1/smtp')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
                ->withParsedBody($body)
        );
    }

    private function sendTest(\Slim\App $app, array $body, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/smtp/test')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
                ->withParsedBody($body)
        );
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testGetNeverReturnsThePasswordItself(): void {
        $app = $this->app();
        $body = self::body($this->get($app));

        $this->assertArrayNotHasKey('password', $body['smtp']);
        $this->assertTrue($body['smtp']['password_set']);
        $this->assertStringNotContainsString('a-known-test-password', (string) $this->get($this->app())->getBody());
    }

    public function testGetIncludesTheMessageTypeList(): void {
        $app = $this->app();
        $body = self::body($this->get($app));

        $this->assertContains('passwdReminder', $body['message_types']);
        $this->assertContains('scheduled_deletion', $body['message_types']);
    }

    public function testGetRequiresAdmin(): void {
        $app = $this->app();
        $this->assertSame(403, $this->get($app, 0)->getStatusCode());
    }

    public function testPatchUpdatesAFieldAndReturnsTheFullPublicShape(): void {
        $app = $this->app();
        $response = $this->patch($app, ['host' => 'smtp.example.it']);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('smtp.example.it', $body['smtp']['host']);
        $this->assertSame('smtp.example.it', Config::get('smtp')['host']);
        $this->assertArrayNotHasKey('password', $body['smtp']);
    }

    public function testPatchWritesAHistoryRow(): void {
        $app = $this->app();
        $this->patch($app, ['host' => 'smtp.example.it']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'smtp'");
        $this->assertNotEmpty($row);
        $this->assertSame('7', (string) $row['user_id']);
        $this->assertStringContainsString('smtp.example.it', $row['data']);
    }

    public function testPatchRejectsAnUnknownField(): void {
        $app = $this->app();
        $response = $this->patch($app, ['bogus' => 1]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPatchRejectsAnUnknownMessageType(): void {
        $app = $this->app();
        $response = $this->patch($app, ['message_types' => ['not-a-real-type']]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPatchRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->patch($app, ['host' => 'smtp.example.it'], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('localhost', Config::get('smtp')['host']);
    }

    public function testSendTestSendsAndPersistsNothing(): void {
        $app = $this->app();
        $response = $this->sendTest($app, ['host' => 'smtp.example.it', 'recipient' => 'admin@example.it']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(self::body($response)['sent']);
        $this->assertCount(1, FakeMailer::$sent);
        $this->assertSame('localhost', Config::get('smtp')['host'], 'a test must never persist');
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'smtp'"), 'a test must never be audited');
    }

    public function testSendTestWorksEvenWhileDisabled(): void {
        $app = $this->app();
        $response = $this->sendTest($app, ['recipient' => 'admin@example.it']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSendTestFailsWithoutARecipient(): void {
        $app = $this->app();
        $response = $this->sendTest($app, ['host' => 'smtp.example.it']);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testSendTestReportsARealFailure(): void {
        $app = $this->app();
        FakeMailer::$shouldFail = true;

        $response = $this->sendTest($app, ['recipient' => 'admin@example.it']);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('simulated SMTP failure', self::error($response));
    }

    public function testSendTestRejectsAnUnknownField(): void {
        $app = $this->app();
        $response = $this->sendTest($app, ['bogus' => 1, 'recipient' => 'admin@example.it']);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSendTestRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->sendTest($app, ['recipient' => 'admin@example.it'], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], FakeMailer::$sent);
    }

    private static function error(ResponseInterface $r): string {
        return (string) (self::body($r)['error'] ?? '');
    }
}
