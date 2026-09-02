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
 * Working through the security log.
 *
 * The log is only useful if there is a way to tell what has been read from what
 * has not, and to find out later who decided a particular entry was fine.
 */
final class HistoryAcknowledgeTest extends TestCase
{
    private function app(): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT,
                 acknowledged_time TEXT DEFAULT NULL, acknowledged_user_id INTEGER DEFAULT NULL)');

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/history.php';
        return $app;
    }

    private function seed(string $object, string $action, string $event = 'login_failed'): int {
        R::exec(
            "INSERT INTO history (user_id, object, object_id, action, network, data) VALUES (1, ?, 1, ?, '203.0.113.0/24', ?)",
            [$object, $action, json_encode(['event' => $event])]
        );
        return (int) R::getCell('SELECT MAX(id) FROM history');
    }

    /**
     * @param array<string, mixed>|null $claims null for an unauthenticated call
     */
    private function call(\Slim\App $app, string $method, string $path, ?array $claims = ['admin' => 1]): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}");

        if ($claims !== null) {
            $token = Auth::issueToken($claims + ['id' => 7, 'username' => 'an-admin', 'has_totp' => false, 'max_token_age' => 60])['token'];
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

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // the outstanding view
    // ---------------------------------------------------------------

    public function testEntriesStartUnacknowledged(): void {
        $app = $this->app();
        $this->seed('security', 'denied');

        $body = self::body($this->call($app, 'GET', '/v1/history?object=security'));

        $this->assertSame(1, $body['outstanding']);
        $this->assertNull($body['history'][0]['acknowledged_time']);
        $this->assertNull($body['history'][0]['acknowledged_user_id']);
    }

    public function testTheOutstandingFilterHidesWhatHasBeenRead(): void {
        $app = $this->app();
        $first  = $this->seed('security', 'denied');
        $second = $this->seed('security', 'login', 'login_succeeded');

        $this->call($app, 'POST', "/v1/history/{$first}/acknowledge");

        $body = self::body($this->call($app, 'GET', '/v1/history?object=security&acknowledged=0'));

        $this->assertCount(1, $body['history']);
        $this->assertSame($second, (int) $body['history'][0]['id']);
        $this->assertSame(1, $body['outstanding']);
    }

    public function testTheAcknowledgedFilterShowsOnlyWhatHasBeenRead(): void {
        $app = $this->app();
        $first = $this->seed('security', 'denied');
        $this->seed('security', 'denied');
        $this->call($app, 'POST', "/v1/history/{$first}/acknowledge");

        $body = self::body($this->call($app, 'GET', '/v1/history?object=security&acknowledged=1'));

        $this->assertCount(1, $body['history']);
        $this->assertSame($first, (int) $body['history'][0]['id']);
    }

    /**
     * The listing exists because a failed login at a username nobody has has no
     * object to be looked up under.
     */
    public function testTheListingIsNotRestrictedToOneObjectId(): void {
        $app = $this->app();
        R::exec("INSERT INTO history (user_id, object, object_id, action, data) VALUES (NULL, 'security', 0, 'denied', '{}')");
        $this->seed('security', 'login', 'login_succeeded');

        $this->assertCount(2, self::body($this->call($app, 'GET', '/v1/history?object=security'))['history']);
    }

    public function testOnlySecurityRowsAreListed(): void {
        $app = $this->app();
        $this->seed('domains', 'update');
        $this->seed('security', 'denied');

        $body = self::body($this->call($app, 'GET', '/v1/history?object=security'));

        $this->assertCount(1, $body['history']);
        $this->assertSame('security', $body['history'][0]['object']);
    }

    // ---------------------------------------------------------------
    // acknowledging
    // ---------------------------------------------------------------

    /**
     * A timestamp and a user, not a flag: an entry that was dismissed is worth
     * being able to ask about later, and "somebody decided this was fine" is
     * not an answer.
     */
    public function testAcknowledgingRecordsWhoAndWhen(): void {
        $app = $this->app();
        $id = $this->seed('security', 'denied');

        $entry = self::body($this->call($app, 'POST', "/v1/history/{$id}/acknowledge"))['entry'];

        $this->assertNotNull($entry['acknowledged_time']);
        $this->assertSame(7, (int) $entry['acknowledged_user_id'], 'the acknowledging admin was not recorded');
    }

    /**
     * Acknowledging says an entry has been read. It must not alter what the
     * entry says happened.
     */
    public function testAcknowledgingDoesNotAlterTheEntry(): void {
        $app = $this->app();
        $id = $this->seed('security', 'denied');
        $before = R::getRow('SELECT user_id, object, object_id, action, network, data FROM history WHERE id = ?', [$id]);

        $this->call($app, 'POST', "/v1/history/{$id}/acknowledge");

        $after = R::getRow('SELECT user_id, object, object_id, action, network, data FROM history WHERE id = ?', [$id]);
        $this->assertSame($before, $after);
    }

    /**
     * The last person to look at it is the one on record.
     */
    public function testReacknowledgingRestampsIt(): void {
        $app = $this->app();
        $id = $this->seed('security', 'denied');
        $this->call($app, 'POST', "/v1/history/{$id}/acknowledge");

        $entry = self::body($this->call($app, 'POST', "/v1/history/{$id}/acknowledge", ['admin' => 1, 'id' => 9]))['entry'];

        $this->assertSame(9, (int) $entry['acknowledged_user_id']);
    }

    public function testAnUnknownEntryIs404(): void {
        $app = $this->app();

        $response = $this->call($app, 'POST', '/v1/history/999/acknowledge');

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // who may do it
    // ---------------------------------------------------------------

    /**
     * A non-admin asking for security events is answered, and told of none.
     * Filters narrow and never widen, so this is an empty list rather than a
     * 403 -- for them, none is the honest answer.
     */
    public function testANonAdminSeesNoSecurityEntries(): void {
        $app = $this->app();
        $this->seed('security', 'denied');

        $this->assertSame(401, $this->call($app, 'GET', '/v1/history?object=security', null)->getStatusCode());

        $body = self::body($this->call($app, 'GET', '/v1/history?object=security', ['admin' => 0]));
        $this->assertSame([], $body['history']);
        $this->assertSame(0, $body['total']);
        $this->assertArrayNotHasKey('outstanding', $body, 'a non-admin was told how many security entries exist');
    }

    public function testAcknowledgingRequiresAdmin(): void {
        $app = $this->app();
        $id = $this->seed('security', 'denied');

        $this->assertSame(403, $this->call($app, 'POST', "/v1/history/{$id}/acknowledge", ['admin' => 0])->getStatusCode());
        $this->assertNull(R::getCell('SELECT acknowledged_time FROM history WHERE id = ?', [$id]));
    }

    /**
     * The per-object route still answers, and still keeps `security` to admins.
     */
    public function testThePerObjectRouteStillWorks(): void {
        $app = $this->app();
        $this->seed('domains', 'update');

        $this->assertSame(200, $this->call($app, 'GET', '/v1/history/domains/1')->getStatusCode());
        $this->assertSame([], self::body($this->call($app, 'GET', '/v1/history/security/1', ['admin' => 0]))['history']);
    }
}
