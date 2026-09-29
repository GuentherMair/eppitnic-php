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
 * Scheduling a deletion (DELETE /v1/domains/{name}?mode=expiry|date) and
 * adding a notice take a real, not-past YYYY-MM-DD; only one deletion per
 * domain can be pending, and scheduling is on record.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ScheduledDeletionDateTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only', 'region' => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT']]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['tasks', 'domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, ex_date TEXT, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, email TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT, exit_code INTEGER,
                 exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, ex_date) VALUES ('ours.it', 2, '2027-05-01')");

        $this->app = AppFactory::create();
        Middleware::register($this->app);
        $app = $this->app;
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/tasks.php';
    }

    private function call(string $method, string $path, array $body = []): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function tasks(string $where): int {
        return (int) R::getCell("SELECT COUNT(*) FROM tasks WHERE {$where}");
    }

    /** @return array<string, array{0: string}> */
    public static function badDates(): array {
        return [
            'not a date'    => ['soon'],
            'wrong shape'   => ['1.10.2030'],
            'no such day'   => ['2030-02-30'],
            'yesterday'     => [date('Y-m-d', strtotime('-1 day'))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDates')]
    public function testASchedulingDateMustBeARealDayNotInThePast(string $date): void {
        $response = $this->call('DELETE', '/v1/domains/ours.it?mode=date&date=' . urlencode($date));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, self::tasks('1 = 1'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDates')]
    public function testANoticeDateIsChecked(string $date): void {
        $response = $this->call('POST', '/v1/domains/ours.it/tasks', ['date' => $date, 'notice' => 'renew']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, self::tasks('1 = 1'));
    }

    public function testTodayAndLaterAreAccepted(): void {
        $this->assertSame(200, $this->call('DELETE', '/v1/domains/ours.it?mode=date&date=' . date('Y-m-d'))->getStatusCode());
        $this->assertSame(201, $this->call('POST', '/v1/domains/ours.it/tasks', ['date' => date('Y-m-d', strtotime('+3 days')), 'notice' => 'renew'])->getStatusCode());
    }

    public function testASecondActiveDeletionIsAConflict(): void {
        $this->assertSame(200, $this->call('DELETE', '/v1/domains/ours.it?mode=date&date=' . date('Y-m-d', strtotime('+2 days')))->getStatusCode());

        $again = $this->call('DELETE', '/v1/domains/ours.it?mode=expiry');

        $this->assertSame(409, $again->getStatusCode());
        $this->assertSame(1, self::tasks("object = 'registry'"));
    }

    public function testADeactivatedDeletionMayBeScheduledAgain(): void {
        $this->call('DELETE', '/v1/domains/ours.it?mode=expiry');
        R::exec('UPDATE tasks SET active = 0');

        $this->assertSame(200, $this->call('DELETE', '/v1/domains/ours.it?mode=expiry')->getStatusCode());
    }

    public function testSchedulingIsRecordedInHistory(): void {
        $date = date('Y-m-d', strtotime('+2 days'));
        $this->call('DELETE', '/v1/domains/ours.it?mode=date&date=' . $date);

        $row = R::getRow("SELECT object, object_id, action, user_id, data FROM history");
        $this->assertSame(['domains', 1, 'update', 4], [$row['object'], (int) $row['object_id'], $row['action'], (int) $row['user_id']]);
        $data = json_decode($row['data'], true);
        $this->assertSame(['scheduled', $date, 1], [$data['scheduled_deletion'], $data['date'], $data['task_id']]);
    }
}
