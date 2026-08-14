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
 * Who may read which history entries.
 *
 * The audit trail holds other people's business: a `users` snapshot carries an
 * email address and an admin flag, and a `security` row carries an address and
 * the headers a request arrived with. Exposing the whole table over the API is
 * only safe if the answer is scoped, so this drives it from two ordinary users
 * and an admin and checks each sees exactly their own.
 */
final class HistoryScopeTest extends TestCase
{
    /** the two ordinary users the fixture belongs to */
    private const ALICE = 2;
    private const BOB   = 3;

    private function app(): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['history', 'domains', 'contacts'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT,
                 acknowledged_time TEXT DEFAULT NULL, acknowledged_user_id INTEGER DEFAULT NULL)');
        R::exec('CREATE TABLE domains  (id INTEGER PRIMARY KEY, domain TEXT, user_id INTEGER)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, user_id INTEGER)');

        // 10/11 are Alice's, 20/21 are Bob's
        R::exec("INSERT INTO domains (id, domain, user_id) VALUES (10, 'alice-one.it', ?), (20, 'bob-one.it', ?)",
            [self::ALICE, self::BOB]);
        R::exec("INSERT INTO contacts (id, handle, user_id) VALUES (11, 'ALICE1', ?), (21, 'BOB1', ?)",
            [self::ALICE, self::BOB]);

        $this->entry('domains', 10, 'create', self::ALICE);
        $this->entry('domains', 20, 'create', self::BOB);
        $this->entry('contacts', 11, 'update', self::ALICE);
        $this->entry('contacts', 21, 'update', self::BOB);
        $this->entry('users', self::ALICE, 'update', self::ALICE);
        $this->entry('users', self::BOB, 'update', self::BOB);
        $this->entry('security', self::ALICE, 'denied', null);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/history.php';
        return $app;
    }

    private function entry(string $object, int $objectId, string $action, ?int $userId): void {
        R::exec(
            'INSERT INTO history (user_id, object, object_id, action, data) VALUES (?, ?, ?, ?, ?)',
            [$userId, $object, $objectId, $action, json_encode(['seeded' => true])]
        );
    }

    private function get(\Slim\App $app, string $path, int $id, bool $admin = false): ResponseInterface {
        $token = Auth::issueToken([
            'id' => $id, 'username' => "user{$id}", 'admin' => $admin ? 1 : 0,
            'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    /**
     * @return array<int, string> "object:object_id" for each entry returned
     */
    private function visible(\Slim\App $app, int $id, bool $admin = false, string $query = ''): array {
        $body = json_decode((string) $this->get($app, '/v1/history' . $query, $id, $admin)->getBody(), true);

        return array_map(fn($row) => $row['object'] . ':' . $row['object_id'], $body['history']);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testAnAdminSeesEverything(): void {
        $app = $this->app();

        $this->assertCount(7, $this->visible($app, 1, true));
    }

    /**
     * The rule the rest of the API already scopes by: your own domains, your
     * own contacts, your own user row.
     */
    public function testAUserSeesOnlyTheirOwnObjects(): void {
        $app = $this->app();

        $alice = $this->visible($app, self::ALICE);
        sort($alice);

        $this->assertSame(['contacts:11', 'domains:10', 'users:2'], $alice);
    }

    public function testAnotherUserSeesTheirsInstead(): void {
        $app = $this->app();

        $bob = $this->visible($app, self::BOB);
        sort($bob);

        $this->assertSame(['contacts:21', 'domains:20', 'users:3'], $bob);
    }

    /**
     * The gap this closes: any valid token used to be able to read any user's
     * history, and those snapshots carry an email address and an admin flag.
     */
    public function testAUserCannotReadAnotherUsersRow(): void {
        $app = $this->app();

        $this->assertSame([], $this->visible($app, self::ALICE, false, '?object=users&object_id=' . self::BOB));
    }

    public function testAUserCannotReadAnotherUsersDomain(): void {
        $app = $this->app();

        $this->assertSame([], $this->visible($app, self::ALICE, false, '?object=domains&object_id=20'));
    }

    /**
     * Asking for an object type they may not see is answered with nothing, not
     * with someone else's rows.
     */
    public function testAUserSeesNoSecurityEntries(): void {
        $app = $this->app();

        $this->assertSame([], $this->visible($app, self::ALICE, false, '?object=security'));
    }

    /**
     * The per-object route is the same query with two filters pre-set, so it
     * must scope identically -- it was the one that leaked.
     */
    public function testThePerObjectRouteIsScopedTheSameWay(): void {
        $app = $this->app();

        $mine = json_decode((string) $this->get($app, '/v1/history/domains/10', self::ALICE)->getBody(), true);
        $theirs = json_decode((string) $this->get($app, '/v1/history/domains/20', self::ALICE)->getBody(), true);

        $this->assertCount(1, $mine['history']);
        $this->assertSame([], $theirs['history'], "another user's domain history was readable");
    }

    /**
     * `total` counts what the caller may see, not what exists -- otherwise it
     * would report how much is being withheld.
     */
    public function testTheTotalIsScopedToo(): void {
        $app = $this->app();

        $body = json_decode((string) $this->get($app, '/v1/history', self::ALICE)->getBody(), true);

        $this->assertSame(3, $body['total']);
    }

    public function testFiltersNarrowWithinWhatIsVisible(): void {
        $app = $this->app();

        $this->assertSame(['domains:10'], $this->visible($app, self::ALICE, false, '?object=domains'));
        $this->assertSame(['domains:10'], $this->visible($app, self::ALICE, false, '?action=create'));
    }

    public function testPagingDoesNotEscapeTheScope(): void {
        $app = $this->app();

        $this->assertCount(1, $this->visible($app, self::ALICE, false, '?limit=1'));
        $this->assertCount(2, $this->visible($app, self::ALICE, false, '?limit=99&offset=1'));
    }

    public function testAnonymousGetsNothing(): void {
        $app = $this->app();
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/history')
        );

        $this->assertSame(401, $response->getStatusCode());
    }
}
