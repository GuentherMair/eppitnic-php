<?php

namespace Net\EPP\Tests\Wire;

use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;
use Net\EPP\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Feeds real registry responses (captured from production traffic and
 * anonymised by tests/capture-responses.php) through the real parsers.
 *
 * This is the half of the codebase that request-generation tests cannot
 * reach. Generating XML and validating it against a schema proves what we
 * send is well-formed; only these prove that what nic.it sends back is
 * understood.
 */
final class ResponseParsingTest extends EppTestCase
{
    private const DIR = __DIR__ . '/../fixtures/responses';

    private static function fixture(string $name): string {
        $path = self::DIR . '/' . $name . '.xml';
        if ( ! is_file($path)) {
            self::markTestSkipped("no captured fixture '{$name}' (run tests/capture-responses.php)");
        }
        return file_get_contents($path);
    }

    public static function allFixtures(): array {
        $cases = [];
        foreach (glob(self::DIR . '/*.xml') ?: [] as $file) {
            $name = basename($file, '.xml');
            $cases[$name] = [$name];
        }
        return $cases ?: ['none' => ['none']];
    }

    /**
     * Every captured response must at minimum survive the generic result
     * handling in AbstractObject::ExecuteQuery() -- a code, a message, and no
     * warnings from walking a structure that isn't shaped as expected.
     */
    #[DataProvider('allFixtures')]
    public function testResponseIsUnderstoodGenerically(string $name): void {
        if ($name === 'none') {
            $this->markTestSkipped('no captured response fixtures yet');
        }

        $this->transport->queue(self::fixture($name));

        $session = new Session($this->nic);
        $session->poll(false, 'req');

        $this->assertNotSame('', (string) $session->svCode, "'{$name}' produced no result code");
        $this->assertMatchesRegularExpression('/^[12]\d{3}$/', (string) $session->svCode);
    }

    public function testDomainInfoIsFullyParsed(): void {
        $this->transport->queue(self::fixture('domain-info-ok'));

        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-1.it'), 'fetch() rejected a real successful domain:info');

        $this->assertNotSame('', $domain->get('registrant'), 'registrant not parsed');
        $this->assertNotEmpty($domain->get('status'), 'status not parsed');
        $this->assertNotSame('', $domain->get('authinfo'), 'authInfo not parsed');
        $this->assertNotSame('', $domain->get('crDate'), 'crDate not parsed');
        $this->assertNotSame('', $domain->get('exDate'), 'exDate not parsed');

        // 'tech' is documented to always come back as an array keyed by handle,
        // even for a single technical contact -- the single-contact case used
        // to return a bare string and silently broke every uniform caller
        $this->assertIsArray($domain->get('tech'));
    }

    public function testContactInfoIsFullyParsed(): void {
        $this->transport->queue(self::fixture('contact-info-ok'));

        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('TESTHANDLE000001'), 'fetch() rejected a real successful contact:info');

        $this->assertNotSame('', $contact->get('name'), 'name not parsed');
        $this->assertNotSame('', $contact->get('email'), 'email not parsed');
        $this->assertNotSame('', $contact->get('countrycode'), 'cc not parsed');
        $this->assertNotEmpty($contact->get('status'), 'status not parsed');
    }

    /**
     * An error response must be reported as a failure with the registry's own
     * code and message preserved -- swallowing either leaves an operator
     * guessing why a command did not take effect.
     */
    public function testErrorResponsesSurfaceCodeAndMessage(): void {
        $this->transport->queue(self::fixture('contact-create-error'));

        $contact = new Contact($this->nic);
        $contact->set('handle', 'TESTHANDLE000001');
        $this->assertFalse($contact->create(), 'a 2xxx response was reported as success');

        $this->assertStringStartsWith('2', (string) $contact->svCode);
        $this->assertNotSame('', $contact->getError());
    }

    /**
     * Poll messages, by registry message type. These drive
     * Session::parsePollReq(), whose branches are the least covered and most
     * shape-sensitive code in the project.
     *
     * @return array<string, array{0: string, 1: string}> fixture => expected parsed type
     */
    public static function pollFixtures(): array {
        return [
            'chgStatusMsgData'       => ['poll-chgStatusMsgData', 'chgStatusMsgData'],
            'simpleMsgData'          => ['poll-simpleMsgData', 'simpleMsgData'],
            'passwdReminder'         => ['poll-passwdReminder', 'passwdReminder'],
            'creditMsgData'          => ['poll-creditMsgData', 'creditMsgData'],
            'pendingTransfer'        => ['poll-pendingTransfer', 'pendingTransfer'],
            'clientApprovedTransfer' => ['poll-clientApprovedTransfer', 'clientApproved'],
            'serverApprovedTransfer' => ['poll-serverApprovedTransfer', 'serverApproved'],
        ];
    }

    #[DataProvider('pollFixtures')]
    public function testPollMessageIsClassified(string $fixture, string $expectedType): void {
        $this->transport->queue(self::fixture($fixture));

        $session = new Session($this->nic);
        $session->poll(false, 'req');

        $parsed = (new \ReflectionMethod(Session::class, 'parsePollReq'))->invoke($session);

        $this->assertStringContainsString(
            $expectedType,
            $parsed['type'],
            "poll message classified as '{$parsed['type']}'"
        );
    }

    /**
     * A message about a specific domain must carry that domain through: the
     * DNS-sync queue and PollProcessor act on it, and a message parsed without
     * one is a message nothing can act on.
     */
    #[DataProvider('pollFixtures')]
    public function testDomainScopedPollMessagesCarryTheirDomain(string $fixture, string $expectedType): void {
        if (in_array($expectedType, ['passwdReminder', 'creditMsgData'], true)) {
            $this->markTestSkipped("{$expectedType} is not about a domain");
        }

        $this->transport->queue(self::fixture($fixture));

        $session = new Session($this->nic);
        $session->poll(false, 'req');
        $parsed = (new \ReflectionMethod(Session::class, 'parsePollReq'))->invoke($session);

        $this->assertNotSame('', $parsed['domain'], "'{$fixture}' lost the domain it refers to");
    }
}
