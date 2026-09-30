<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Archiving the poll queue in bulk. The registry delivers messages all the
 * time, so "everything" has to mean everything up to a moment the caller
 * named -- what arrived after they loaded the list has not been read.
 */
final class PollQueueArchiveTest extends TestCase
{
    private function app(): \Slim\App {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'domains', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, status TEXT)');
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, domain TEXT, data TEXT,
                 archived_user_id INTEGER DEFAULT NULL, archived_time TEXT DEFAULT NULL,
                 created_time TEXT DEFAULT CURRENT_TIMESTAMP)');

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    private function seedAt(string $created): int {
        R::exec("INSERT INTO messages (type, domain, data, created_time) VALUES ('chgStatusMsgData', 'example.it', '', ?)", [$created]);
        return (int) R::getCell('SELECT MAX(id) FROM messages');
    }

    /**
     * @param array<string, mixed> $body the parsed request body
     * @param array<string, mixed> $claims token claims replacing the defaults
     */
    private function archive(\Slim\App $app, array $body, int $admin = 1, array $claims = []): ResponseInterface {
        $token = TestAccounts::issueToken($claims + [
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/poll-queue/archive')
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private static function archivedTime(int $id): ?string {
        $value = R::getCell('SELECT archived_time FROM messages WHERE id = ?', [$id]);
        return $value === null || $value === false ? null : (string) $value;
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testEverythingUpToTheMomentIsArchived(): void {
        $app = $this->app();
        $old = $this->seedAt('2026-08-05 10:00:00');
        $exact = $this->seedAt('2026-08-06 00:50:16');

        $body = self::body($this->archive($app, ['until' => '2026-08-06 00:50:16']));

        $this->assertSame(2, $body['archived'], 'the boundary itself is included');
        $this->assertNotNull(self::archivedTime($old));
        $this->assertNotNull(self::archivedTime($exact));
    }

    public function testWhatIsNewerStaysInTheQueue(): void {
        $app = $this->app();
        $this->seedAt('2026-08-06 00:50:16');
        $newer = $this->seedAt('2026-08-06 00:50:17');

        $body = self::body($this->archive($app, ['until' => '2026-08-06 00:50:16']));

        $this->assertSame(1, $body['archived']);
        $this->assertNull(self::archivedTime($newer));
        $this->assertSame(1, $body['outstanding'], 'the answer says what is still in the queue');
    }

    /**
     * `created_time` is only second-resolution: two messages landing in the
     * same second as the one the caller saw are otherwise indistinguishable
     * from it. `until_id` is exact.
     */
    public function testUntilIdIsExactWithinTheSameSecond(): void {
        $app = $this->app();
        $seen = $this->seedAt('2026-08-06 00:50:16');
        $sameSecond = $this->seedAt('2026-08-06 00:50:16');

        $body = self::body($this->archive($app, ['until' => '2026-08-06 00:50:16', 'until_id' => $seen]));

        $this->assertSame(1, $body['archived']);
        $this->assertSame($seen, $body['until_id']);
        $this->assertNotNull(self::archivedTime($seen));
        $this->assertNull(self::archivedTime($sameSecond), 'a same-second arrival stays in the queue when until_id says it is newer');
    }

    public function testUntilIdMustBeAnInteger(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $response = $this->archive($app, ['until' => '2026-08-06 00:50:16', 'until_id' => 'not-a-number']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(self::archivedTime($id));
    }

    public function testTheAnswerCarriesTheStampWrittenToEveryRow(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $body = self::body($this->archive($app, ['until' => '2026-08-06 00:50:16']));

        $this->assertSame(self::archivedTime($id), $body['archived_time']);
        $this->assertSame(7, $body['archived_user_id']);
    }

    public function testItRecordsWhoArchived(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $this->archive($app, ['until' => '2026-08-06 00:50:16']);

        $this->assertSame(7, (int) R::getCell('SELECT archived_user_id FROM messages WHERE id = ?', [$id]));
    }

    public function testWhatWasAlreadyArchivedKeepsItsStamp(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');
        R::exec("UPDATE messages SET archived_time = '2026-08-07 09:00:00', archived_user_id = 3 WHERE id = ?", [$id]);

        $body = self::body($this->archive($app, ['until' => '2026-09-01 00:00:00']));

        $this->assertSame(0, $body['archived']);
        $this->assertSame(3, (int) R::getCell('SELECT archived_user_id FROM messages WHERE id = ?', [$id]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAMoment(): array {
        return [
            'missing'     => [null],
            'a date only' => ['2026-08-06'],
            'month 13'    => ['2026-13-01 00:00:00'],
            'not a date'  => ['yesterday'],
        ];
    }

    #[DataProvider('notAMoment')]
    public function testItRefusesWhatIsNotAMoment(mixed $until): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $response = $this->archive($app, $until === null ? [] : ['until' => $until]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(self::archivedTime($id), 'a refused call archives nothing');
    }

    public function testArchivingEverythingRequiresAdmin(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $response = $this->archive($app, ['until' => '2026-09-01 00:00:00'], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(self::archivedTime($id));
    }

    // ---------------------------------------------------------------
    // archiving the messages a screen shows, by id
    // ---------------------------------------------------------------

    public function testNamedIdsAreArchivedAndNothingElse(): void {
        $app = $this->app();
        $shown = $this->seedAt('2026-08-06 00:50:16');
        $alsoShown = $this->seedAt('2026-08-06 00:50:17');
        $notShown = $this->seedAt('2026-08-06 00:50:18');

        $body = self::body($this->archive($app, ['ids' => [$shown, (string) $alsoShown]]));

        $this->assertSame(2, $body['archived']);
        $this->assertSame(1, $body['outstanding']);
        $this->assertSame(7, $body['archived_user_id']);
        $this->assertSame($body['archived_time'], self::archivedTime($shown), 'the answer carries the stamp written');
        $this->assertNull(self::archivedTime($notShown));
    }

    public function testNamedIdsKeepAnEarlierStamp(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');
        R::exec("UPDATE messages SET archived_time = '2026-08-07 09:00:00', archived_user_id = 3 WHERE id = ?", [$id]);

        $body = self::body($this->archive($app, ['ids' => [$id]]));

        $this->assertSame(0, $body['archived']);
        $this->assertSame('2026-08-07 09:00:00', self::archivedTime($id));
        $this->assertSame(3, (int) R::getCell('SELECT archived_user_id FROM messages WHERE id = ?', [$id]));
    }

    /** more than one statement's worth of placeholders */
    public function testAWholeCachedQueueCanBeNamed(): void {
        $app = $this->app();
        R::exec('WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2500)
                 INSERT INTO messages (type, domain, data) SELECT \'chgStatusMsgData\', \'example.it\', \'\' FROM n');

        $body = self::body($this->archive($app, [
            'ids' => array_map('intval', R::getCol('SELECT id FROM messages')),
        ]));

        $this->assertSame(2500, $body['archived']);
        $this->assertSame(0, $body['outstanding']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAListOfIds(): array {
        return [
            'an empty list'  => [[]],
            'a plain number' => [5],
            'not a number'   => [[1, 'two']],
        ];
    }

    #[DataProvider('notAListOfIds')]
    public function testItRefusesIdsThatAreNotAListOfIntegers(mixed $ids): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $response = $this->archive($app, ['ids' => $ids]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(self::archivedTime($id), 'a refused call archives nothing');
    }

    public function testAManagerCannotArchiveAnotherResellersMessageById(): void {
        $app = $this->app();
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 2), ('other.it', 3)");
        $ours = $this->seedAt('2026-08-06 00:50:16');
        $theirs = $this->seedAt('2026-08-06 00:50:17');
        R::exec("UPDATE messages SET domain = 'other.it' WHERE id = ?", [$theirs]);

        $body = self::body($this->archive($app, ['ids' => [$ours, $theirs]], 0, [
            'id' => 8, 'role' => 'manager', 'reseller_id' => 2,
        ]));

        $this->assertSame(1, $body['archived']);
        $this->assertSame(0, $body['outstanding'], 'counted within what the manager sees');
        $this->assertNotNull(self::archivedTime($ours));
        $this->assertNull(self::archivedTime($theirs));
    }

    public function testArchivingByIdRequiresAdmin(): void {
        $app = $this->app();
        $id = $this->seedAt('2026-08-06 00:50:16');

        $response = $this->archive($app, ['ids' => [$id]], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(self::archivedTime($id));
    }
}
