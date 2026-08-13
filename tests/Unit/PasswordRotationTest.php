<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Client;
use Eppitnic\Config;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * Recovering from a password rotation that did not finish.
 *
 * The rotation's dangerous moment is between the registry accepting the new
 * password and this installation recording it: a process killed in that window
 * leaves the registry holding a credential nobody here knows. It used to be
 * survived by printing the password to the cron log, which put the credential
 * somewhere worse than the database and made recovery a human errand.
 *
 * The candidate is now written before it is sent, so both possibilities are on
 * disk and the registry can be asked which one is live. These drive that
 * question with each answer it can give.
 */
final class PasswordRotationTest extends EppTestCase
{
    /** @var FakeTransport[] one per Client the code under test asked for */
    private array $transports = [];

    /** @var string[] the passwords each login was attempted with, in order */
    private array $attempted = [];

    /** what the registry will accept; anything else is refused */
    private string $livePassword = 'old-password';

    protected function setUp(): void {
        parent::setUp();

        // Config::set() writes through to the settings table, so this needs a
        // database -- an in-memory SQLite one, since what is being tested is
        // the ordering of the writes, not the dialect. RedBean holds its
        // connection globally and refuses a second setup() for the same key,
        // so the connection is made once and the tables are rebuilt per test.
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS messages');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, data TEXT, archived_time TEXT)');

        Config::loadForTesting(self::SETTINGS);
        $this->seedEpp(['password' => 'old-password']);

        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $this->transports[] = $transport;

            // The registry's answer depends on the credential offered, which
            // is the whole point: a login is how the caller finds out which
            // password is live. Queue enough for hello + login + logout.
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request): string {
                $this->attempted[] = self::credentialIn($request);
                return self::credentialIn($request) === $this->livePassword
                    ? CommandCatalog::OK_RESPONSE
                    : self::loginRefused();
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);

            return $client;
        });
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        parent::tearDown();
    }

    /**
     * The registry took the new password, and the process died before it was
     * recorded. The candidate is the live credential and must be promoted.
     */
    public function testCandidateIsPromotedWhenTheRegistryAcceptsIt(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'new-password';

        RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('new-password', $epp['password'], 'the live password was not promoted');
        $this->assertArrayNotHasKey('pendingPassword', $epp, 'the candidate was left behind');
    }

    /**
     * The process died before the registry saw the change. The old password is
     * still live and the candidate is worthless.
     */
    public function testCandidateIsDiscardedWhenTheOldPasswordStillWorks(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'old-password';

        RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('old-password', $epp['password']);
        $this->assertArrayNotHasKey('pendingPassword', $epp);
    }

    /**
     * Neither works -- the account is locked, the IP is not authorised,
     * something else entirely. Discarding the candidate here would throw away
     * what may well be the live credential, so it is kept.
     */
    public function testCandidateIsKeptWhenNeitherPasswordWorks(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'something-else';

        $log = RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('new-password', $epp['pendingPassword'], 'the candidate was discarded');
        $this->assertStringContainsString('CRITICAL', implode("\n", $log));
    }

    /**
     * The candidate is tried first. If the old password were tried first and
     * the rotation had in fact landed, the probe would fail and the candidate
     * be discarded -- locking the installation out with the answer on disk.
     */
    public function testTheCandidateIsTriedBeforeTheStoredPassword(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'new-password';

        RegistryPasswordChange::reconcile();

        $this->assertSame(['new-password'], $this->attempted, 'the stored password should not have been tried');
    }

    /**
     * Nothing to settle is not an error, and costs no registry round trip.
     */
    public function testNoCandidateIsANoOp(): void {
        $this->seedEpp(['password' => 'old-password']);

        $this->assertSame([], RegistryPasswordChange::reconcile());
        $this->assertSame([], $this->attempted, 'the registry was contacted with nothing to reconcile');
    }

    /**
     * The candidate reaches the settings table before it reaches the registry.
     * This is the property the whole design rests on: if the write came second,
     * a crash in between would leave no record of it anywhere.
     */
    public function testTheCandidateIsPersistedBeforeItIsSent(): void {
        $this->seedEpp(['password' => 'old-password']);

        $seenAtLogin = null;
        RegistryPasswordChange::useClientFactory(function () use (&$seenAtLogin): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request) use (&$seenAtLogin): string {
                // read the table, not the cache: what survives a crash here is
                // what has been written, not what the process happens to hold
                $stored = json_decode(R::getCell("SELECT `value` FROM settings WHERE `key` = 'epp'"), true);
                $seenAtLogin = $stored['pendingPassword'] ?? null;
                return CommandCatalog::OK_RESPONSE;
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);
            return $client;
        });

        R::exec("INSERT INTO messages (type, data, archived_time) VALUES ('passwdReminder', '2026-09-01', NULL)");

        RegistryPasswordChange::rotateOnReminder();

        $this->assertNotNull($seenAtLogin, 'no candidate was on disk when the change was sent');
        $this->assertSame(Config::get('epp')['password'], $seenAtLogin, 'a different password was sent than was recorded');
    }

    /**
     * @param array<string, mixed> $overrides merged over the base epp setting
     */
    private function seedEpp(array $overrides): void {
        Config::set('epp', $overrides + self::SETTINGS['epp']);
    }

    /**
     * The password a login request offers as its credential (not <newPW>).
     */
    private static function credentialIn(string $request): string {
        $dom = new \DOMDocument();
        if ( ! @$dom->loadXML($request)) {
            return '';
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
        $pw = $xpath->query('//e:login/e:pw')->item(0);
        return $pw === null ? '' : $pw->textContent;
    }

    private static function loginRefused(): string {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="2200"><msg>Authentication error</msg></result>
                <trID><svTRID>x</svTRID></trID>
              </response>
            </epp>
            XML;
    }
}
