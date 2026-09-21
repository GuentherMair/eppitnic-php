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
 * The poll queue runs to ~3 MB, so a screen that wants only the latest few can
 * ask for them, and still be told how many there are.
 */
final class PollQueueListTest extends TestCase
{
    private function app(int $unarchived = 12, int $archived = 3): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS messages');
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, domain TEXT, data TEXT,
                 archived_user_id INTEGER DEFAULT NULL, archived_time TEXT DEFAULT NULL,
                 created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        for ($i = 0; $i < $archived; $i++) {
            R::exec("INSERT INTO messages (type, domain, data, archived_time) VALUES ('old', 'a.it', '', '2026-01-01 00:00:00')");
        }
        for ($i = 0; $i < $unarchived; $i++) {
            R::exec("INSERT INTO messages (type, domain, data) VALUES ('new', 'b.it', '')");
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    private function get(\Slim\App $app, string $query = '', int $admin = 1): ResponseInterface {
        $token = Auth::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', "http://localhost/v1/poll-queue{$query}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testWithoutALimitItIsTheWholeQueueAsBefore(): void {
        $body = self::body($this->get($this->app()));

        $this->assertCount(12, $body['messages'], 'unarchived only, by default');
        $this->assertSame(12, $body['total']);
    }

    public function testALimitReturnsTheNewestFewAndStillCountsThemAll(): void {
        $body = self::body($this->get($this->app(), '?limit=10'));

        $this->assertCount(10, $body['messages']);
        $this->assertSame(12, $body['total'], 'how many matched, not how many came back');
        $this->assertGreaterThan((int) $body['messages'][9]['id'], (int) $body['messages'][0]['id'], 'newest first');
    }

    public function testTheLimitWorksWithinWhicheverSetWasAskedFor(): void {
        $body = self::body($this->get($this->app(), '?active=0&limit=5'));

        $this->assertCount(5, $body['messages']);
        $this->assertSame(15, $body['total'], 'archived ones included');
    }

    public function testANonsenseLimitIsClamped(): void {
        $app = $this->app();

        $this->assertCount(1, self::body($this->get($app, '?limit=0'))['messages']);
        $this->assertCount(12, self::body($this->get($app, '?limit=100000'))['messages']);
    }

    public function testItStaysAdminOnly(): void {
        $this->assertSame(403, $this->get($this->app(), '?limit=10', 0)->getStatusCode());
    }
}
