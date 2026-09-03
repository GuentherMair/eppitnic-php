<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command;
use Eppitnic\Config;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The behaviour several classes now share, rather than each holding a copy. The
 * copies were the risk: one had drifted into a real bug and another had the
 * same bug fixed twice. A test on the shared thing tests every caller of it.
 */
final class SharedBehaviourTest extends EppTestCase
{
    // -----------------------------------------------------------------
    // AbstractObject::checkAvailability() -- Contact::check()/Domain::check()
    // -----------------------------------------------------------------

    /**
     * The bug found and fixed twice, once per copy: the argument is cast to an
     * array before it is tested, so array(null) is a one-element array and the
     * test never fires. Both must refuse rather than send a <check> for
     * nothing.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nothingToCheck(): array {
        return [
            'null'               => [null],
            'empty string'       => [''],
            'array of one null'  => [[null]],
            'array of one blank' => [['']],
            'empty array'        => [[]],
            'blanks only'        => [['', '']],
        ];
    }

    #[DataProvider('nothingToCheck')]
    public function testContactCheckRefusesWhenThereIsNothingToCheck(mixed $argument): void {
        $result = (new Contact($this->nic))->check($argument);

        $this->assertFalse($result->answered(), 'a check with no names must not be reported as answered');
        $this->assertStringContainsString('handle', $result->error());
        $this->assertSame([], $this->transport->requests, 'nothing should have been sent');
    }

    #[DataProvider('nothingToCheck')]
    public function testDomainCheckRefusesWhenThereIsNothingToCheck(mixed $argument): void {
        $result = (new Domain($this->nic))->check($argument);

        $this->assertFalse($result->answered(), 'a check with no names must not be reported as answered');
        $this->assertStringContainsString('domain', $result->error());
        $this->assertSame([], $this->transport->requests, 'nothing should have been sent');
    }

    /**
     * Each object reads its identifier from a different child of <cd> --
     * contacts <id>, domains <name> -- one of the six values
     * checkAvailability() takes. The wrong one keys every answer by ''.
     */
    public function testContactCheckReadsTheIdentifierFromCdId(): void {
        $this->transport->queue(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="1000"><msg>Command completed successfully</msg></result>
                <resData>
                  <contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                    <contact:cd><contact:id avail="true">FREE-HANDLE</contact:id></contact:cd>
                    <contact:cd><contact:id avail="false">TAKEN-HANDLE</contact:id><contact:reason>in use</contact:reason></contact:cd>
                  </contact:chkData>
                </resData>
                <trID><clTRID>x</clTRID><svTRID>y</svTRID></trID>
              </response>
            </epp>
            XML);

        $result = (new Contact($this->nic))->check(['FREE-HANDLE', 'TAKEN-HANDLE']);

        $this->assertTrue($result->answered(), $result->error());
        $this->assertSame(['FREE-HANDLE'], $result->availableNames());
        $this->assertSame('in use', $result->reason('TAKEN-HANDLE'));
    }

    public function testDomainCheckReadsTheIdentifierFromCdName(): void {
        $this->transport->queue(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="1000"><msg>Command completed successfully</msg></result>
                <resData>
                  <domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                    <domain:cd><domain:name avail="true">free.it</domain:name></domain:cd>
                    <domain:cd><domain:name avail="false">taken.it</domain:name><domain:reason>already registered</domain:reason></domain:cd>
                  </domain:chkData>
                </resData>
                <trID><clTRID>x</clTRID><svTRID>y</svTRID></trID>
              </response>
            </epp>
            XML);

        $result = (new Domain($this->nic))->check(['free.it', 'taken.it']);

        $this->assertTrue($result->answered(), $result->error());
        $this->assertSame(['free.it'], $result->availableNames());
        $this->assertSame('already registered', $result->reason('taken.it'));
    }

    /**
     * The registry accepts at most five names per <check>, and the cap now
     * lives on AbstractObject rather than being set to 5 by each subclass.
     */
    public function testOnlyTheFirstFiveNamesAreSent(): void {
        $this->transport->queueAll('');

        (new Domain($this->nic))->check(['a.it', 'b.it', 'c.it', 'd.it', 'e.it', 'f.it']);

        $sent = $this->transport->lastRequest();
        $this->assertStringContainsString('e.it', $sent);
        $this->assertStringNotContainsString('f.it', $sent, 'the sixth name is past the registry\'s limit');
    }

    /**
     * The one thing Domain::check() still does for itself, and therefore the
     * one thing moving the rest to the parent could have lost.
     */
    public function testDomainCheckStillSetsSvMsgForASingleUnavailableName(): void {
        $this->transport->queue(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="1000"><msg>Command completed successfully</msg></result>
                <resData>
                  <domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                    <domain:cd><domain:name avail="false">taken.it</domain:name><domain:reason>already registered</domain:reason></domain:cd>
                  </domain:chkData>
                </resData>
                <trID><clTRID>x</clTRID><svTRID>y</svTRID></trID>
              </response>
            </epp>
            XML);

        $domain = new Domain($this->nic);
        $domain->check('taken.it');

        $this->assertSame('already registered', $domain->svMsg);
    }

    // -----------------------------------------------------------------
    // AbstractObject::applyStatusChange()
    // -----------------------------------------------------------------

    /**
     * Each object accepts a different set of client states, and that set is the
     * parameter while everything around it is shared -- so a domain-only state
     * offered to a contact is still refused, before anything is sent.
     */
    public function testAContactRefusesADomainOnlyState(): void {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'SOME-HANDLE');

        $this->assertFalse($contact->updateStatus('clientTransferProhibited'));
        $this->assertStringContainsString('clientTransferProhibited', $contact->getError());
        $this->assertSame([], $this->transport->requests);
    }

    public function testADomainAcceptsItsOwnWiderStateSet(): void {
        $this->transport->queueAll('');

        $domain = new Domain($this->nic);
        $domain->set('domain', 'example.it');
        $domain->updateStatus('clientTransferProhibited');

        // what matters is that it got past the state check and sent something;
        // the empty canned answer is not a result this test reads
        $this->assertNotSame([], $this->transport->requests, 'a state this object accepts must reach the registry');
    }

    public function testAnUnknownAddDelIsRefusedByBoth(): void {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'SOME-HANDLE');
        $this->assertFalse($contact->updateStatus('clientDeleteProhibited', 'toggle'));
        $this->assertStringContainsString("expecting either 'add' or 'rem'", $contact->getError());

        $domain = new Domain($this->nic);
        $domain->set('domain', 'example.it');
        $this->assertFalse($domain->updateStatus('clientDeleteProhibited', 'toggle'));
        $this->assertStringContainsString("expecting either 'add' or 'rem'", $domain->getError());

        $this->assertSame([], $this->transport->requests);
    }

    // -----------------------------------------------------------------
    // Config::EPP_PUBLIC_FIELDS
    // -----------------------------------------------------------------

    /**
     * The allow-list's whole purpose: a field added to `epp` stays withheld
     * until named here. Asserting the constant's contents is the point -- a
     * credential quietly joining it should fail a test, not depend on review.
     */
    public function testTheEppAllowListNamesOnlyNonSecretFields(): void {
        $this->assertSame([
            'server', 'server_deleted', 'port', 'interface',
            'username', 'lang', 'cl_trid_prefix', 'lastPasswordUpdate',
        ], Config::EPP_PUBLIC_FIELDS);

        foreach (['password', 'pendingPassword'] as $secret) {
            $this->assertNotContains($secret, Config::EPP_PUBLIC_FIELDS, "{$secret} must never be published");
        }
    }

    // -----------------------------------------------------------------
    // Cli\Command's shared helpers
    // -----------------------------------------------------------------

    public function testItemFailedCountsAndNamesTheItem(): void {
        $command = $this->bulkCommand();
        $stream = fopen('php://memory', 'w+');
        $command->useErrorStream($stream);

        $command->fail('example.it', 'the registry said no');
        $command->fail('other.it', '');

        rewind($stream);
        $written = (string) stream_get_contents($stream);

        $this->assertStringContainsString('example.it: the registry said no', $written);
        // an object that simply is not there answers with no error text at all
        $this->assertStringContainsString('other.it: not found', $written);
        $this->assertSame(7, $command->exitCode(7), "any failure must produce the command's own code");
    }

    public function testOutcomeIsZeroWhenNothingFailed(): void {
        $this->assertSame(0, $this->bulkCommand()->exitCode(7));
    }

    /**
     * Six is the registry's ceiling for both nameservers and technical
     * contacts (xsd/domain-1.0.xsd), and blanks left by a trailing separator
     * must not be offered as entries.
     */
    public function testSplitListTrimsFiltersAndCapsAtSix(): void {
        $command = $this->bulkCommand();

        $this->assertSame(['a', 'b'], $command->split(' a : b : '));
        $this->assertSame([], $command->split(''));
        $this->assertSame(
            ['1', '2', '3', '4', '5', '6'],
            $command->split('1:2:3:4:5:6:7:8'),
            "the seventh entry would be refused by the registry anyway"
        );
    }

    /**
     * The --file text and the local dry-run/yes pair are built by helpers now.
     * This catches a helper returning the wrong shape for any command that
     * adopted it, without naming them one by one.
     */
    public function testEveryCommandStillDeclaresUsableOptions(): void {
        foreach (Application::commands() as $verb => $class) {
            $options = (new $class())->options();

            foreach ($options as $name => $help) {
                $this->assertIsString($name, "{$verb} has a non-string option name");
                $this->assertNotSame('', trim((string) $help), "{$verb}'s --{$name} has no help text");
            }
        }
    }

    /**
     * Only the plain one-name-per-line ones fileOption() describes: `config
     * migrate`'s --file takes a config.xml and three domain verbs take rows.
     * This catches a fourth command hand-copying the shared sentence.
     */
    public function testEveryListReadingFileOptionUsesTheSharedText(): void {
        $listReaders = [];
        foreach (Application::commands() as $verb => $class) {
            $help = (new $class())->options()['file='] ?? null;
            if ($help !== null && str_contains($help, 'one per line')) {
                $listReaders[$verb] = $help;
            }
        }

        $this->assertGreaterThan(10, count($listReaders), 'the --file option should be widespread');
        foreach ($listReaders as $verb => $help) {
            $this->assertMatchesRegularExpression(
                // the optional bracketed tail is fileOption()'s qualifier
                '/^read .+ from this file, one per line( \([^)]+\))?$/',
                $help,
                "{$verb}'s --file text is off-pattern"
            );
        }
    }

    /**
     * A Command with the protected helpers exposed, so they can be driven
     * directly rather than through fifteen subclasses.
     */
    private function bulkCommand(): Command {
        return new class extends Command {
            public function describe(): string { return 'test double'; }
            public function run(): int { return 0; }
            public function fail(string $item, string $error): void { $this->itemFailed($item, $error); }
            public function exitCode(int $code): int { return $this->outcome($code); }
            /** @return string[] */
            public function split(string $value): array { return $this->splitList($value); }
        };
    }
}
