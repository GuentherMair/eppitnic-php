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
 * Tasks belong to their domain's reseller: everyone in it sees them, and may
 * deactivate a notice or a scheduled deletion -- a DNS-sync event only an admin.
 * A deactivated deletion is recorded against the domain.
 */
final class TasksScopeTest extends TestCase
{
    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['user', 2],
        3 => ['user', 3],
    ];

    /** task ids, by what they are */
    private const NOTICE = 1, DELETION = 2, PDNS = 3, THEIRS = 4;

    private function app(): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['tasks', 'domains', 'users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, email TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT, exit_code INTEGER,
                 exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('ours.it', 2), ('theirs.it', 3)");
        R::exec("INSERT INTO tasks (id, domain, date, notice, object, action) VALUES
                 (1, 'ours.it', '2026-10-01', 'renew', NULL, NULL),
                 (2, 'ours.it', '2026-10-01', 'scheduled deletion', 'registry', 'delete'),
                 (3, 'ours.it', '2026-10-01', 'domain created', 'pdns', 'create'),
                 (4, 'theirs.it', '2026-10-01', 'scheduled deletion', 'registry', 'delete')");
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/tasks.php';
        return $app;
    }

    private function call(\Slim\App $app, string $method, string $path, int $as): ResponseInterface {
        [$role, $reseller] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'role' => $role, 'reseller_id' => $reseller, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    /** @return int[] */
    private function visibleIds(\Slim\App $app, int $as): array {
        $rows = json_decode((string) $this->call($app, 'GET', '/v1/tasks', $as)->getBody(), true)['rows'];
        $ids = array_map('intval', array_column($rows, 'id'));
        sort($ids);
        return $ids;
    }

    private static function active(int $id): int {
        return (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$id]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testAUserSeesTheResellersTasksOnly(): void {
        $app = $this->app();

        $this->assertSame([self::NOTICE, self::DELETION, self::PDNS], $this->visibleIds($app, 2));
        $this->assertSame([self::THEIRS], $this->visibleIds($app, 3));
        $this->assertSame([1, 2, 3, 4], $this->visibleIds($app, 1));
    }

    public function testTheTotalIsScopedToo(): void {
        $app = $this->app();
        $body = json_decode((string) $this->call($app, 'GET', '/v1/tasks', 3)->getBody(), true);

        $this->assertSame(1, $body['total']);
    }

    public function testAUserMayDeactivateTheResellersScheduledDeletionAndNotice(): void {
        $app = $this->app();

        $this->assertSame(200, $this->call($app, 'DELETE', '/v1/tasks/' . self::DELETION, 2)->getStatusCode());
        $this->assertSame(200, $this->call($app, 'DELETE', '/v1/tasks/' . self::NOTICE, 2)->getStatusCode());
        $this->assertSame(0, self::active(self::DELETION));
        $this->assertSame(0, self::active(self::NOTICE));
    }

    public function testADeactivatedDeletionIsRecordedAgainstTheDomain(): void {
        $app = $this->app();
        $this->call($app, 'DELETE', '/v1/tasks/' . self::DELETION, 2);
        $this->call($app, 'DELETE', '/v1/tasks/' . self::DELETION, 2);

        $rows = R::getAll('SELECT * FROM history');
        $this->assertCount(1, $rows, 'once: deactivating it again changes nothing');
        $this->assertSame(['domains', 1, 'update', 2], [$rows[0]['object'], (int) $rows[0]['object_id'], $rows[0]['action'], (int) $rows[0]['user_id']]);
        $this->assertSame(
            ['domain' => 'ours.it', 'scheduled_deletion' => 'deactivated', 'date' => '2026-10-01', 'task_id' => self::DELETION],
            json_decode($rows[0]['data'], true)
        );
    }

    public function testADeactivatedNoticeIsNotRecorded(): void {
        $app = $this->app();
        $this->call($app, 'DELETE', '/v1/tasks/' . self::NOTICE, 2);

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testAUserMayNotDeactivateADnsSyncEvent(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'DELETE', '/v1/tasks/' . self::PDNS, 2)->getStatusCode());
        $this->assertSame(1, self::active(self::PDNS));

        $this->assertSame(200, $this->call($app, 'DELETE', '/v1/tasks/' . self::PDNS, 1)->getStatusCode(), 'an admin may');
    }

    public function testAnotherResellersTaskIsOutOfReach(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'DELETE', '/v1/tasks/' . self::THEIRS, 2)->getStatusCode());
        $this->assertSame(1, self::active(self::THEIRS));
    }
}
