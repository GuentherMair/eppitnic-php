<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeMailer;
use RedBeanPHP\R;

/**
 * The `smtp` setting (validate/persist/audit), each user's own filter, and
 * the actual send-or-not decision -- the single place `config smtp-set`,
 * `PATCH /v1/smtp` and both notifying jobs share.
 */
final class NotifierTest extends EppTestCase
{
    private const ENABLED_SYSTEM = [
        'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
        'recipient_mode' => 'system', 'recipient' => 'admin@example.it', 'username' => '', 'password' => '',
        'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
    ];

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'history', 'users', 'domains'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        // users are recipients by default here (notify_enabled 1); a test
        // that wants a muted one says so
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER DEFAULT 2, email TEXT, active INTEGER DEFAULT 1,
                 notify_enabled INTEGER DEFAULT 1, notify_message_types TEXT, notify_fulltext TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER)');

        Config::loadForTesting(static::SETTINGS + [
            'smtp' => [
                'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
                'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);

        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));
    }

    protected function tearDown(): void {
        Notifier::useMailerFactory(null);
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // settings: fields/get/preview/set
    // -----------------------------------------------------------------

    public function testFieldsListsEveryField(): void {
        $this->assertSame(
            ['enabled', 'host', 'port', 'sender', 'recipient_mode', 'recipient', 'username', 'password', 'auth_type', 'message_types', 'fulltext'],
            Notifier::fields()
        );
    }

    public function testGetReturnsStoredValues(): void {
        $this->assertSame('localhost', Notifier::get()['host']);
        $this->assertSame([], Notifier::get()['message_types']);
    }

    public function testSetRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['bogus' => 1], 1);
    }

    public function testHostCannotBeUnset(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['host' => null], 1);
    }

    public function testPortMustBeInRange(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['port' => '70000'], 1);
    }

    public function testPortCanBeUnset(): void {
        Notifier::set(['port' => '2525'], 1);
        Notifier::set(['port' => null], 1);
        $this->assertNull(Config::get('smtp')['port']);
    }

    public function testSenderMustBeAValidEmail(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['sender' => 'not-an-email'], 1);
    }

    public function testRecipientModeMustBeOneOfTheFour(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['recipient_mode' => 'nobody'], 1);
    }

    public function testAuthTypeMustBeOneOfTheThree(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['auth_type' => 'kerberos'], 1);
    }

    /**
     * Saving is never blocked by an in-progress configuration -- a blank
     * recipient under system/both just means nothing is sent to it yet,
     * checked at send time (see testNotifyPollSkipsAnUnconfiguredSystemRecipient),
     * not enforced here.
     */
    public function testRecipientModeSystemCanBeSavedWithoutARecipientYet(): void {
        $result = Notifier::set(['recipient_mode' => 'system'], 1);
        $this->assertSame('system', $result['recipient_mode']);
        $this->assertSame('', $result['recipient']);
    }

    public function testRecipientIsNotRequiredWhenModeIsUser(): void {
        $result = Notifier::set(['recipient_mode' => 'user'], 1);
        $this->assertSame('user', $result['recipient_mode']);
    }

    public function testMessageTypesRejectsAnUnknownType(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::set(['message_types' => ['not-a-real-type']], 1);
    }

    public function testMessageTypesAcceptsKnownTypes(): void {
        $result = Notifier::set(['message_types' => ['passwdReminder', 'scheduled_deletion']], 1);
        $this->assertSame(['passwdReminder', 'scheduled_deletion'], $result['message_types']);
    }

    public function testSetPreservesFieldsNotBeingChanged(): void {
        Notifier::set(self::ENABLED_SYSTEM, 1);
        Notifier::set(['host' => 'smtp.example.it'], 1);
        $this->assertSame('admin@example.it', Config::get('smtp')['recipient']);
    }

    public function testSetRecordsOneHistoryRowWithTheChangesOnly(): void {
        Notifier::set(['host' => 'smtp.example.it'], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'smtp'");
        $this->assertNotEmpty($row);
        $this->assertSame('42', (string) $row['user_id']);
        $this->assertStringContainsString('smtp.example.it', $row['data']);
    }

    public function testPasswordIsRedactedInHistory(): void {
        Notifier::set(['password' => 'a-real-secret'], 1);

        $data = (string) R::getCell("SELECT data FROM history WHERE object = 'smtp'");
        $this->assertStringNotContainsString('a-real-secret', $data);
        $this->assertStringContainsString('[redacted]', $data);
    }

    // -----------------------------------------------------------------
    // notifyPoll()
    // -----------------------------------------------------------------

    public function testNotifyPollDoesNothingWhileDisabled(): void {
        Notifier::notifyPoll('passwdReminder', null, 'a reminder');
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testNotifyPollSendsToSystemRecipient(): void {
        Notifier::set(self::ENABLED_SYSTEM, 1);
        Notifier::notifyPoll('passwdReminder', null, 'a reminder');

        $this->assertCount(1, FakeMailer::$sent);
        $this->assertSame('admin@example.it', FakeMailer::$sent[0]['to']);
        $this->assertStringContainsString('passwdReminder', FakeMailer::$sent[0]['subject']);
    }

    public function testRecipientModeNoneSendsNothing(): void {
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 2)");
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'none']), 1);
        Notifier::notifyPoll('chgStatusMsgData', 'example.it', 'status changed to ok');

        $this->assertSame([], FakeMailer::$sent);
    }

    public function testNotifyPollRespectsTheSystemTypeFilter(): void {
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['message_types' => ['dnsWarningMsgData']]), 1);
        Notifier::notifyPoll('passwdReminder', null, 'a reminder');

        $this->assertSame([], FakeMailer::$sent);
    }

    public function testNotifyPollRespectsTheSystemFulltextFilter(): void {
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['fulltext' => 'expired']), 1);
        Notifier::notifyPoll('chgStatusMsgData', 'example.it', 'status changed to ok');

        $this->assertSame([], FakeMailer::$sent);
    }

    public function testAccountLevelMessagesNeverReachAnIndividualUser(): void {
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'both']), 1);

        // no $domain at all -- passwdReminder is account-level, not domain-scoped
        Notifier::notifyPoll('passwdReminder', null, 'a reminder');

        $this->assertCount(1, FakeMailer::$sent, 'only the system recipient, never a guessed user');
        $this->assertSame('admin@example.it', FakeMailer::$sent[0]['to']);
    }

    public function testNotifyPollSendsToTheDomainsResellersRecipients(): void {
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 2)");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'both']), 1);

        Notifier::notifyPoll('chgStatusMsgData', 'example.it', 'status changed');

        $recipients = array_column(FakeMailer::$sent, 'to');
        $this->assertContains('admin@example.it', $recipients);
        $this->assertContains('owner@example.it', $recipients);
        $this->assertCount(2, FakeMailer::$sent);
    }

    public function testNotifyPollReachesEveryRecipientOfTheResellerButNoOther(): void {
        R::exec("INSERT INTO users (id, reseller_id, email, notify_enabled, active) VALUES
                 (5, 2, 'alice@example.it', 1, 1), (6, 2, 'bob@example.it', 1, 1),
                 (7, 2, 'muted@example.it', 0, 1), (8, 2, 'gone@example.it', 1, 0), (9, 3, 'elsewhere@example.it', 1, 1)");
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 2)");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'user']), 1);

        Notifier::notifyPoll('chgStatusMsgData', 'example.it', 'status changed');

        $recipients = array_column(FakeMailer::$sent, 'to');
        sort($recipients);
        $this->assertSame(['alice@example.it', 'bob@example.it'], $recipients, 'not muted, not inactive, not another reseller');
    }

    public function testNotifyPollHonoursTheOwningUsersOwnFilter(): void {
        R::exec("INSERT INTO users (id, email, notify_message_types) VALUES (5, 'owner@example.it', ?)", [json_encode(['dnsWarningMsgData'])]);
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('example.it', 2)");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'user']), 1);

        Notifier::notifyPoll('chgStatusMsgData', 'example.it', 'status changed');

        $this->assertSame([], FakeMailer::$sent, "the owner's own filter did not include chgStatusMsgData");
    }

    public function testNotifyPollSkipsAnUnconfiguredSystemRecipient(): void {
        Notifier::set(['enabled' => true, 'recipient_mode' => 'system'], 1);
        Notifier::notifyPoll('passwdReminder', null, 'a reminder');

        $this->assertSame([], FakeMailer::$sent, 'recipient_mode is system but no recipient is set yet');
    }

    public function testEmptyMessageTypesMeansEveryTypePasses(): void {
        Notifier::set(self::ENABLED_SYSTEM, 1);
        Notifier::notifyPoll('unknown', null, 'something odd');

        $this->assertCount(1, FakeMailer::$sent);
    }

    // -----------------------------------------------------------------
    // notifyDeletions()
    // -----------------------------------------------------------------

    public function testNotifyDeletionsDoesNothingForAnEmptyRun(): void {
        Notifier::set(self::ENABLED_SYSTEM, 1);
        Notifier::notifyDeletions([]);
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testNotifyDeletionsSendsOneSystemSummaryForEveryOutcome(): void {
        Notifier::set(self::ENABLED_SYSTEM, 1);
        Notifier::notifyDeletions([
            ['domain' => 'a.it', 'reseller_id' => null, 'ok' => true, 'message' => 'domain deleted'],
            ['domain' => 'b.it', 'reseller_id' => null, 'ok' => false, 'message' => 'registry refused'],
        ]);

        $this->assertCount(1, FakeMailer::$sent);
        $this->assertStringContainsString('a.it: deleted', FakeMailer::$sent[0]['body']);
        $this->assertStringContainsString('b.it: FAILED — registry refused', FakeMailer::$sent[0]['body']);
    }

    public function testNotifyDeletionsSendsEachResellerItsOwnDomainsOnly(): void {
        R::exec("INSERT INTO users (id, reseller_id, email) VALUES (5, 2, 'alice@example.it'), (6, 3, 'bob@example.it')");
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'both']), 1);

        Notifier::notifyDeletions([
            ['domain' => 'a.it', 'reseller_id' => 2, 'ok' => true, 'message' => 'domain deleted'],
            ['domain' => 'b.it', 'reseller_id' => 3, 'ok' => true, 'message' => 'domain deleted'],
        ]);

        // one system summary (both outcomes) + one per reseller's recipient (its own domain only)
        $this->assertCount(3, FakeMailer::$sent);
        $byRecipient = [];
        foreach (FakeMailer::$sent as $sent) {
            $byRecipient[$sent['to']] = $sent['body'];
        }
        $this->assertStringContainsString('a.it', $byRecipient['alice@example.it']);
        $this->assertStringNotContainsString('b.it', $byRecipient['alice@example.it']);
        $this->assertStringContainsString('b.it', $byRecipient['bob@example.it']);
        $this->assertStringNotContainsString('a.it', $byRecipient['bob@example.it']);
    }

    public function testNotifyDeletionsSkipsOutcomesWithNoOwner(): void {
        Notifier::set(array_merge(self::ENABLED_SYSTEM, ['recipient_mode' => 'user']), 1);

        Notifier::notifyDeletions([
            ['domain' => 'a.it', 'reseller_id' => null, 'ok' => true, 'message' => 'domain deleted'],
        ]);

        $this->assertSame([], FakeMailer::$sent, 'no owner to notify, and mode is user-only');
    }

    // -----------------------------------------------------------------
    // sendTest()
    // -----------------------------------------------------------------

    public function testSendTestRequiresARecipient(): void {
        $result = Notifier::sendTest(['host' => 'smtp.example.it']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('recipient', $result['error']);
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testSendTestSendsWithTheGivenOverrides(): void {
        $result = Notifier::sendTest(['host' => 'smtp.example.it', 'recipient' => 'admin@example.it']);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, FakeMailer::$sent);
        $this->assertSame('admin@example.it', FakeMailer::$sent[0]['to']);
        $this->assertSame('smtp.example.it', FakeMailer::$sent[0]['host']);
    }

    /** A blank/omitted password in the overrides reuses whatever is already saved. */
    public function testSendTestReusesTheStoredPasswordWhenNoneIsGiven(): void {
        Notifier::set(['username' => 'mailer', 'password' => 'a-stored-secret'], 1);

        Notifier::sendTest(['recipient' => 'admin@example.it']);

        $this->assertSame('a-stored-secret', FakeMailer::$sent[0]['password']);
    }

    public function testSendTestWorksEvenWhileDisabled(): void {
        // setUp()'s own default -- smtp.enabled stays false throughout
        $result = Notifier::sendTest(['recipient' => 'admin@example.it']);

        $this->assertTrue($result['ok']);
    }

    /**
     * Unlike send() (used by notifyPoll()/notifyDeletions()), a failure here
     * is reported back rather than logged and swallowed -- the whole point
     * is telling the admin why it did not work.
     */
    public function testSendTestReportsAFailureInsteadOfSwallowingIt(): void {
        FakeMailer::$shouldFail = true;

        $result = Notifier::sendTest(['recipient' => 'admin@example.it']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('simulated SMTP failure', $result['error']);
    }

    public function testSendTestRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::sendTest(['bogus' => 1, 'recipient' => 'admin@example.it']);
    }

    public function testSendTestValidatesItsOverrides(): void {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::sendTest(['recipient' => 'admin@example.it', 'auth_type' => 'kerberos']);
    }

    // -----------------------------------------------------------------
    // per-user preferences
    // -----------------------------------------------------------------

    public function testLoadUserPreferencesReturnsNullForAnUnknownUser(): void {
        $this->assertNull(Notifier::loadUserPreferences(999));
    }

    public function testLoadUserPreferencesDefaultsToUnfiltered(): void {
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        R::exec('UPDATE users SET notify_enabled = 0 WHERE id = 5');
        $this->assertSame(['enabled' => false, 'message_types' => [], 'fulltext' => ''], Notifier::loadUserPreferences(5));
    }

    public function testSaveUserPreferencesRoundTrips(): void {
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        $result = Notifier::saveUserPreferences(5, ['message_types' => ['dnsWarningMsgData'], 'fulltext' => 'expired']);

        $this->assertTrue($result['ok']);
        $this->assertSame(['dnsWarningMsgData'], $result['settings']['message_types']);
        $this->assertSame('expired', $result['settings']['fulltext']);
        $this->assertSame(['dnsWarningMsgData'], Notifier::loadUserPreferences(5)['message_types']);
    }

    public function testSaveUserPreferencesTogglesNotifications(): void {
        R::exec("INSERT INTO users (id, email, notify_enabled) VALUES (5, 'owner@example.it', 0)");

        $this->assertTrue(Notifier::saveUserPreferences(5, ['enabled' => true])['settings']['enabled']);
        $this->assertFalse(Notifier::saveUserPreferences(5, ['enabled' => 'no'])['settings']['enabled']);
        $this->assertSame(400, Notifier::saveUserPreferences(5, ['enabled' => 'maybe'])['status']);
    }

    public function testSaveUserPreferencesRejectsAnUnknownType(): void {
        R::exec("INSERT INTO users (id, email) VALUES (5, 'owner@example.it')");
        $result = Notifier::saveUserPreferences(5, ['message_types' => ['not-a-real-type']]);

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['status']);
    }

    public function testSaveUserPreferencesFailsForAnUnknownUser(): void {
        $result = Notifier::saveUserPreferences(999, ['fulltext' => 'x']);
        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['status']);
    }
}
