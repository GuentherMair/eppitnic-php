<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigEppPasswordCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `config epp-password` -- the one epp.* setting that goes through
 * RegistryPasswordChange rather than a plain local write, since a value
 * this installation and the registry disagree about breaks every EPP call.
 */
final class ConfigEppPasswordCommandTest extends EppTestCase
{
    /** what a login attempt is offered, in order (<pw>, or <newPW> when present) */
    private array $attempted = [];

    /** whether the registry accepts whatever password it is offered */
    private bool $registryAccepts = true;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');

        Config::loadForTesting(static::SETTINGS);
        Config::set('epp', ['password' => 'old-password'] + static::SETTINGS['epp']);

        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);

            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request): string {
                $this->attempted[] = self::credentialIn($request);
                return $this->registryAccepts ? CommandCatalog::OK_RESPONSE : self::loginRefused();
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);

            return $client;
        });
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        parent::tearDown();
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testChangesThePasswordAtTheRegistryAndStoresIt(): void {
        $command = new ConfigEppPasswordCommand(['--yes', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('new-password', Config::get('epp')['password']);
        // apply() offers the new password as <newPW> in the same login that
        // still authenticates with the old one -- the credential this test's
        // FakeTransport reads back is the one it was offered as the login
        $this->assertArrayNotHasKey('pendingPassword', Config::get('epp'));
    }

    public function testChangeStampsLastPasswordUpdate(): void {
        Config::set('epp', ['password' => 'old-password', 'lastPasswordUpdate' => 0] + static::SETTINGS['epp']);

        $command = new ConfigEppPasswordCommand(['--yes', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $command->run());

        $this->assertGreaterThan(0, Config::get('epp')['lastPasswordUpdate']);
    }

    public function testForceAdoptsWithoutChangingWhenTheRegistryAlreadyAcceptsIt(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--force', 'already-live']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('already-live', Config::get('epp')['password']);
    }

    public function testForceRefusesAndChangesNothingWhenTheRegistryDisagrees(): void {
        $this->registryAccepts = false;

        $command = new ConfigEppPasswordCommand(['--yes', '--force', 'not-real']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $exit = $command->run();

        $this->assertSame(CHANGE_PASSWORD_FAILED, $exit);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testDryRunSendsNothingToTheRegistry(): void {
        $command = new ConfigEppPasswordCommand(['--dry-run', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame([], $this->attempted, 'a dry run must reach no network at all');
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigEppPasswordCommand(['new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame([], $this->attempted);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testRejectsAPasswordShorterThanSix(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppPasswordCommand(['short']))->run();
    }

    public function testRejectsAPasswordLongerThanSixteen(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppPasswordCommand([str_repeat('a', 17)]))->run();
    }

    /**
     * The password a login request offers as its credential -- <newPW> when
     * present (a real change), otherwise <pw> (a --force verification).
     */
    private static function credentialIn(string $request): string {
        $dom = new \DOMDocument();
        if ( ! @$dom->loadXML($request)) {
            return '';
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
        $newPw = $xpath->query('//e:login/e:newPW')->item(0);
        if ($newPw !== null) {
            return $newPw->textContent;
        }
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
