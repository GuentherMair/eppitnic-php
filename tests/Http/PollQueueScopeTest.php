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
 * The poll queue beyond admins: anyone sees the messages about their
 * reseller's domains (and pending transfer-ins), a manager may also archive
 * those, and an account-level message without a domain stays the admins'.
 */
final class PollQueueScopeTest extends TestCase
{
    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['manager', 2],
        3 => ['user', 2],
        4 => ['manager', 3],
    ];

    /** message ids, by what they are about */
    private const OURS = 1, OUR_TRANSFER = 2, THEIRS = 3, ACCOUNT = 4;

    private function app(): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'domains', 'transfers', 'users', 'resellers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, domain TEXT, data TEXT,
                 archived_user_id INTEGER DEFAULT NULL, archived_time TEXT DEFAULT NULL,
                 created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER)');
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('ours.it', 2), ('theirs.it', 3)");
        R::exec("INSERT INTO transfers (domain, reseller_id) VALUES ('incoming.it', 2)");
        R::exec("INSERT INTO messages (id, type, domain, data) VALUES
                 (1, 'chgStatusMsgData', 'ours.it', ''), (2, 'pendingTransfer', 'incoming.it', ''),
                 (3, 'chgStatusMsgData', 'theirs.it', ''), (4, 'creditMsgData', NULL, '')");
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    private function call(\Slim\App $app, string $method, string $path, int $as, array $body = []): ResponseInterface {
        [$role, $reseller] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'role' => $role, 'reseller_id' => $reseller, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function json(ResponseInterface $r): array {
        return (array) json_decode((string) $r->getBody(), true);
    }

    /** @return int[] */
    private function visibleIds(\Slim\App $app, int $as): array {
        $ids = array_map('intval', array_column(self::json($this->call($app, 'GET', '/v1/poll-queue', $as))['messages'], 'id'));
        sort($ids);
        return $ids;
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testAnAdminSeesEverything(): void {
        $this->assertSame([1, 2, 3, 4], $this->visibleIds($this->app(), 1));
    }

    public function testAUserSeesTheirResellersDomainsAndTransfersOnly(): void {
        $app = $this->app();

        $this->assertSame([self::OURS, self::OUR_TRANSFER], $this->visibleIds($app, 3));
        $this->assertSame([self::THEIRS], $this->visibleIds($app, 4));
    }

    public function testOneMessageOutsideTheScopeIsNotThere(): void {
        $app = $this->app();

        $this->assertSame(200, $this->call($app, 'GET', '/v1/poll-queue/' . self::OURS, 3)->getStatusCode());
        $this->assertSame(404, $this->call($app, 'GET', '/v1/poll-queue/' . self::THEIRS, 3)->getStatusCode());
        $this->assertSame(404, $this->call($app, 'GET', '/v1/poll-queue/' . self::ACCOUNT, 3)->getStatusCode());
    }

    public function testAPlainUserCannotArchive(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'POST', '/v1/poll-queue/' . self::OURS . '/archive', 3)->getStatusCode());
        $this->assertNull(R::getCell('SELECT archived_time FROM messages WHERE id = ?', [self::OURS]));
    }

    public function testAManagerArchivesTheirResellersMessageButNotAnothers(): void {
        $app = $this->app();

        $this->assertSame(200, $this->call($app, 'POST', '/v1/poll-queue/' . self::OURS . '/archive', 2)->getStatusCode());
        $this->assertSame(404, $this->call($app, 'POST', '/v1/poll-queue/' . self::THEIRS . '/archive', 2)->getStatusCode());
        $this->assertNotNull(R::getCell('SELECT archived_time FROM messages WHERE id = ?', [self::OURS]));
        $this->assertNull(R::getCell('SELECT archived_time FROM messages WHERE id = ?', [self::THEIRS]));
    }

    public function testArchivingAllArchivesOnlyTheManagersOwn(): void {
        $app = $this->app();

        $response = $this->call($app, 'POST', '/v1/poll-queue/archive', 2, ['until' => '2999-01-01 00:00:00']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, self::json($response)['archived']);
        $this->assertSame(0, self::json($response)['outstanding'], 'counted within what the manager sees');
        $this->assertSame([self::THEIRS, self::ACCOUNT],
            array_map('intval', R::getCol('SELECT id FROM messages WHERE archived_time IS NULL ORDER BY id')));
    }

    public function testArchivingAllIsRefusedToAPlainUser(): void {
        $this->assertSame(403, $this->call($this->app(), 'POST', '/v1/poll-queue/archive', 3, ['until' => '2999-01-01 00:00:00'])->getStatusCode());
    }
}
