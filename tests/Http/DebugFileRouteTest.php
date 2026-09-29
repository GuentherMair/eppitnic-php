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
 * `GET`/`PUT /v1/debugfile` and `GET /v1/debugfile/content` -- the API
 * surface over DebugFile (see DebugFileTest for its path rules).
 */
final class DebugFileRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'debugfile'       => '',
        'allowed_origins' => [],
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'          => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT'],
    ];

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/eppitnic-debugfile-route-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->dir = (string) realpath($this->dir);
        putenv('EPPITNIC_VAR_DIR=' . $this->dir);
    }

    protected function tearDown(): void {
        putenv('EPPITNIC_VAR_DIR');
        @unlink($this->dir . '/epp.log');
        @rmdir($this->dir);
        Config::reset();
        parent::tearDown();
    }

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

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/debugfile.php';
        return $app;
    }

    private function token(int $admin = 1): string {
        return TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
    }

    private function request(\Slim\App $app, string $method, string $path = '/v1/debugfile', ?array $body = null, int $admin = 1): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('Authorization', 'Bearer ' . $this->token($admin));
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testGetReportsLoggingOffWithTheWarning(): void {
        $body = self::body($this->request($this->app(), 'GET'));

        $this->assertSame('', $body['debugfile']['path']);
        $this->assertSame($this->dir, $body['debugfile']['directory']);
        $this->assertStringContainsString('masked', $body['warning']);
    }

    public function testEverythingRequiresAdmin(): void {
        $app = $this->app();
        $this->assertSame(403, $this->request($app, 'GET', '/v1/debugfile', null, 0)->getStatusCode());
        $this->assertSame(403, $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log'], 0)->getStatusCode());
        $this->assertSame(403, $this->request($app, 'GET', '/v1/debugfile/content', null, 0)->getStatusCode());
        $this->assertSame(403, $this->request($app, 'DELETE', '/v1/debugfile', null, 0)->getStatusCode());
        $this->assertSame('', Config::get('debugfile'));
    }

    public function testAManagerIsRefusedEverywhere(): void {
        $app = $this->app();
        $token = TestAccounts::issueToken([
            'id' => 8, 'username' => 'manager', 'role' => 'manager', 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
        $calls = [
            ['GET', '/v1/debugfile', null],
            ['PUT', '/v1/debugfile', ['path' => 'epp.log']],
            ['DELETE', '/v1/debugfile', null],
            ['GET', '/v1/debugfile/content', null],
        ];
        foreach ($calls as [$method, $path, $body]) {
            $request = (new ServerRequestFactory())
                ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '203.0.113.9'])
                ->withHeader('Authorization', 'Bearer ' . $token);
            if ($body !== null) {
                $request = $request->withParsedBody($body);
            }
            $this->assertSame(403, $app->handle($request)->getStatusCode(), "{$method} {$path} as a manager");
        }
        $this->assertSame('', Config::get('debugfile'));
        $this->assertFileDoesNotExist($this->dir . '/epp.log');
    }

    public function testPutStartsAndDeleteRemovesTheLog(): void {
        $app = $this->app();

        $on = $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log']);
        $this->assertSame(200, $on->getStatusCode());
        $this->assertSame($this->dir . '/epp.log', self::body($on)['debugfile']['path']);
        $this->assertFileExists($this->dir . '/epp.log');

        $off = $this->request($app, 'DELETE');
        $this->assertSame(200, $off->getStatusCode());
        $this->assertSame('', self::body($off)['debugfile']['path']);
        $this->assertFileDoesNotExist($this->dir . '/epp.log');
    }

    public function testPutWhileRecordingIsAConflict(): void {
        $app = $this->app();
        $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log']);

        $this->assertSame(409, $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'other.log'])->getStatusCode());
        $this->assertSame($this->dir . '/epp.log', Config::get('debugfile'));
    }

    public function testPutNoLongerTurnsLoggingOff(): void {
        $app = $this->app();
        $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log']);

        $this->assertSame(400, $this->request($app, 'PUT', '/v1/debugfile', ['path' => null])->getStatusCode());
        $this->assertSame($this->dir . '/epp.log', Config::get('debugfile'));
    }

    public function testAFailedDeleteKeepsTheSettingAndSaysWhy(): void {
        mkdir($this->dir . '/sub', 0700);
        $app = $this->app();
        $this->request($app, 'PUT', '/v1/debugfile', ['path' => $this->dir . '/sub/epp.log']);
        chmod($this->dir . '/sub', 0500);
        try {
            $response = $this->request($app, 'DELETE');
        } finally {
            chmod($this->dir . '/sub', 0700);
            @unlink($this->dir . '/sub/epp.log');
            @rmdir($this->dir . '/sub');
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('cannot delete', self::body($response)['error']);
        $this->assertSame($this->dir . '/sub/epp.log', Config::get('debugfile'));
    }

    public function testPutRefusesAPathOutsideTheVarDirectory(): void {
        $app = $this->app();
        $response = $this->request($app, 'PUT', '/v1/debugfile', ['path' => '/tmp/epp.log']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', Config::get('debugfile'));
    }

    public function testPutRefusesAMissingPath(): void {
        $this->assertSame(400, $this->request($this->app(), 'PUT', '/v1/debugfile', ['file' => 'epp.log'])->getStatusCode());
    }

    public function testContentIs404WhileTheLogIsEmpty(): void {
        $app = $this->app();
        $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log']);

        $this->assertSame(404, $this->request($app, 'GET', '/v1/debugfile/content')->getStatusCode());
    }

    public function testContentReturnsTheLogAsTextAndAuditsTheRead(): void {
        $app = $this->app();
        $this->request($app, 'PUT', '/v1/debugfile', ['path' => 'epp.log']);
        file_put_contents($this->dir . '/epp.log', "==== START OUTPUT ====\n<hello/>\n");

        $response = $this->request($app, 'GET', '/v1/debugfile/content');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<hello/>', (string) $response->getBody());
        $row = R::getRow("SELECT * FROM history WHERE object = 'security'");
        $this->assertSame('debugfile_read', json_decode($row['data'], true)['event']);
    }
}
