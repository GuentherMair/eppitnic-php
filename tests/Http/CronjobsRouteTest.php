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
 * `GET`/`PATCH /v1/cronjobs*` -- the API surface over CronjobSettings, the
 * same class every `config *-set` CLI command uses (see CronjobSettingsTest
 * for that shared layer's own validation coverage).
 */
final class CronjobsRouteTest extends TestCase
{
    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'               => 'test-signing-key-for-this-suite-only',
            'pdns'                  => ['enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
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
        return TestAccounts::issueToken([
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
        $this->assertSame([], $body['jobs']['pdns']['apis']);
        $this->assertSame([], $body['jobs']['pdns']['nameservers']);
        $this->assertSame(['enabled' => false], $body['jobs']['keepalive']);
    }

    public function testGetRedactsApiKeys(): void {
        $app = $this->app();
        $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'super-secret'],
        ]]);

        $body = self::body($this->get($app));

        $this->assertTrue($body['jobs']['pdns']['apis'][0]['api_key_set']);
        $this->assertArrayNotHasKey('api_key', $body['jobs']['pdns']['apis'][0]);
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

    public function testPatchRejectsAnInvalidApisEntry(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'ftp', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'k'],
        ]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], Config::get('pdns')['apis']);
    }

    public function testPatchAcceptsApisAndKeepsABlankKeyOnAKnownEndpoint(): void {
        $app = $this->app();
        $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'stored-key'],
        ]]);

        $response = $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => ''],
        ]]);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stored-key', Config::get('pdns')['apis'][0]['api_key'], 'the stored key was not kept');
        $this->assertArrayNotHasKey('api_key', $body['settings']['apis'][0], 'the PATCH response echoed a real key');
        $this->assertTrue($body['settings']['apis'][0]['api_key_set']);
    }

    public function testPatchRejectsANewApisEntryWithNoKey(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081],
        ]]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPatchRejectsMoreThanSixApis(): void {
        $app = $this->app();
        $apis = [];
        for ($i = 1; $i <= 7; $i++) {
            $apis[] = ['protocol' => 'http', 'host' => "ns{$i}", 'port' => 8081, 'api_key' => 'k'];
        }

        $response = $this->patch($app, 'pdns', ['apis' => $apis]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPatchAcceptsExactlySixApis(): void {
        $app = $this->app();
        $apis = [];
        for ($i = 1; $i <= 6; $i++) {
            $apis[] = ['protocol' => 'http', 'host' => "ns{$i}", 'port' => 8081, 'api_key' => 'k'];
        }

        $response = $this->patch($app, 'pdns', ['apis' => $apis]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(6, Config::get('pdns')['apis']);
    }

    public function testHistoryNeverContainsARealApiKey(): void {
        $app = $this->app();
        $this->patch($app, 'pdns', ['apis' => [
            ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'super-secret'],
        ]]);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertStringNotContainsString('super-secret', $row['data']);
    }

    public function testPatchAcceptsNameservers(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['nameservers' => ['NS1.Example.IT.', 'ns2.example.it']]);
        $body = self::body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ns1.example.it', 'ns2.example.it'], $body['settings']['nameservers']);
        $this->assertSame(['ns1.example.it', 'ns2.example.it'], Config::get('pdns')['nameservers']);
    }

    public function testPatchRejectsAnIpAddressAsANameserver(): void {
        $app = $this->app();
        $response = $this->patch($app, 'pdns', ['nameservers' => ['192.0.2.1']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], Config::get('pdns')['nameservers']);
    }

    public function testPatchRejectsMoreThanSixNameservers(): void {
        $app = $this->app();
        $hosts = [];
        for ($i = 1; $i <= 7; $i++) {
            $hosts[] = "ns{$i}.example.it";
        }

        $response = $this->patch($app, 'pdns', ['nameservers' => $hosts]);

        $this->assertSame(400, $response->getStatusCode());
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
