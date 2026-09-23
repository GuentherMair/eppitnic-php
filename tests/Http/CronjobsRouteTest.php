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
 * `GET`/`PATCH /v1/cronjobs*` -- the API surface over CronjobSettings, the
 * same class every `config *-set` CLI command uses (see CronjobSettingsTest
 * for that shared layer's own validation coverage).
 */
final class CronjobsRouteTest extends TestCase
{
    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'               => 'test-signing-key-for-this-suite-only',
            'pdns'                  => ['enabled' => false, 'path' => null, 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
            'domain_sync'           => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 0, 'frequency_minutes' => 5, 'last_run_at' => null],
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
            'poll_process'          => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
            'keepalive'             => false,
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/cronjobs.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return Auth::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function get(\Slim\App $app, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/cronjobs')
                ->withHeader('Authorization', 'Bearer ' . $this->token($admin))
        );
    }

    private function patch(\Slim\App $app, string $job, array $body, int $admin = 1): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('PATCH', "http://localhost/v1/cronjobs/{$job}")
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

    public function testGetListsEveryRegisteredJob(): void {
        $app = $this->app();
        $body = self::body($this->get($app));

        $this->assertSame(
            ['pdns', 'domain_sync', 'domain_reap_deletions', 'poll_process', 'keepalive'],
            array_keys($body['jobs'])
        );
        $this->assertSame(3600, $body['jobs']['pdns']['ttl']);
        $this->assertSame(['enabled' => false], $body['jobs']['keepalive']);
    }

    public function testGetRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->get($app, 0);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPatchUpdatesAJobAndReturnsItsFullSettings(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['ttl' => 7200]);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pdns', $body['job']);
        $this->assertSame(7200, $body['settings']['ttl']);
        $this->assertSame(7200, Config::get('pdns')['ttl']);
    }

    public function testPatchPreservesFieldsNotBeingChanged(): void {
        $app = $this->app();
        $this->patch($app, 'pdns', ['ttl' => 7200]);

        $this->assertFalse(Config::get('pdns')['enabled'], 'an unrelated field must survive the partial update');
    }

    public function testPatchWritesAHistoryRow(): void {
        $app = $this->app();
        $this->patch($app, 'domain_sync', ['enabled' => true]);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertSame('7', (string) $row['user_id']);
        $this->assertStringContainsString('domain_sync', $row['data']);
    }

    public function testPatchRejectsAnUnknownJob(): void {
        $app = $this->app();
        $response = $this->patch($app, 'bogus', ['enabled' => true]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', self::body($response));
    }

    public function testPatchRejectsAnUnknownField(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['bogus' => 1]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPatchRejectsANonExecutablePathWithoutForce(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['path' => '/nonexistent/pdnsutil']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(Config::get('pdns')['path']);
    }

    public function testPatchForceOverridesTheExecutableCheck(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['path' => '/nonexistent/pdnsutil', 'force' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/nonexistent/pdnsutil', Config::get('pdns')['path']);
    }

    public function testPatchCanDisablePollProcess(): void {
        $app = $this->app();
        $response = $this->patch($app, 'poll_process', ['enabled' => false]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(self::body($response)['settings']['enabled']);
        $this->assertFalse(Config::get('poll_process')['enabled']);
    }

    public function testPatchRequiresAdmin(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['ttl' => 7200], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(3600, Config::get('pdns')['ttl']);
    }
}
