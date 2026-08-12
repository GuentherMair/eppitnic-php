<?php

namespace Net\EPP\Tests\Wire;

use Net\EPP\IT\Session;
use Net\EPP\Tests\Support\EppTestCase;
use Net\EPP\Tests\Support\RegistrySchemas;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The poll message types that have branches but no captured traffic.
 *
 * Three of the message types the registry declares have never occurred in this
 * installation's queue, so there is nothing to capture and the samples below
 * are hand-built from xsd/extdom-2.0.xsd and xsd/extepp-2.0.xsd.
 *
 * A hand-built sample is only worth something if it is checked back against
 * the schema it was written from -- otherwise a misreading of the structure
 * produces a sample that matches the parser because both are wrong in the same
 * way. So every sample here is schema-validated first, and only then fed to
 * the parser. If the sample is invalid the test fails on that, not on the
 * classification.
 */
final class UnobservedPollMessageTest extends EppTestCase
{
    private static function response(string $extension, string $title): string {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
          <response>
            <result code="1301"><msg lang="en">Command completed successfully; ack to dequeue</msg></result>
            <msgQ count="1" id="4711"><qDate>2026-01-01T00:00:00.000+01:00</qDate><msg lang="en">{$title}</msg></msgQ>
            <extension>
        {$extension}
            </extension>
            <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
          </response>
        </epp>
        XML;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     *         name => [extension XML, title, expected type, expected domain]
     */
    public static function samples(): array {
        return [
            'remappedIdnData' => [
                <<<'XML'
                      <extdom:remappedIdnData xmlns:extdom="http://www.nic.it/ITNIC-EPP/extdom-2.0">
                        <extdom:idnRequested>xn--caff-8oa.it</extdom:idnRequested>
                        <extdom:idnCreated>xn--caff-dma.it</extdom:idnCreated>
                      </extdom:remappedIdnData>
                XML,
                'IDN has been remapped',
                'remappedIdnData',
                // the created name is the one that now exists at the registry
                'xn--caff-dma.it',
            ],

            'refundRenewsForBulkTransferMsgData' => [
                <<<'XML'
                      <extdom:refundRenewsForBulkTransferMsgData xmlns:extdom="http://www.nic.it/ITNIC-EPP/extdom-2.0">
                        <extdom:domainsNum>42</extdom:domainsNum>
                        <extdom:amount>123.45</extdom:amount>
                        <extdom:bulkTransferId>BT-2026-0001</extdom:bulkTransferId>
                      </extdom:refundRenewsForBulkTransferMsgData>
                XML,
                'Refund renews for bulk transfer',
                'refundRenewsForBulkTransferMsgData',
                // a bulk operation spans many domains: there is no single one to record
                '',
            ],

            'wrongNamespaceReminder' => [
                <<<'XML'
                      <extepp:wrongNamespaceReminder xmlns:extepp="http://www.nic.it/ITNIC-EPP/extepp-2.0">
                        <extepp:wrongNamespaceInfo>
                          <extepp:wrongNamespace>http://www.nic.it/ITNIC-EPP/extdom-1.0</extepp:wrongNamespace>
                          <extepp:rightNamespace>http://www.nic.it/ITNIC-EPP/extdom-2.0</extepp:rightNamespace>
                        </extepp:wrongNamespaceInfo>
                      </extepp:wrongNamespaceReminder>
                XML,
                'You are using an outdated namespace',
                'wrongNamespaceReminder',
                '',
            ],
        ];
    }

    #[DataProvider('samples')]
    public function testSampleIsSchemaValid(string $extension, string $title, string $type, string $domain): void {
        $errors = RegistrySchemas::validate(self::response($extension, $title));

        $this->assertSame(
            [],
            $errors,
            "the hand-built sample for '{$type}' is not valid against xsd/:\n  - " . implode("\n  - ", $errors)
        );
    }

    #[DataProvider('samples')]
    public function testSampleIsClassified(string $extension, string $title, string $type, string $domain): void {
        $this->transport->queue(self::response($extension, $title));

        $session = new Session($this->nic);
        $session->poll(false, 'req');
        $parsed = (new \ReflectionMethod(Session::class, 'parsePollReq'))->invoke($session);

        $this->assertSame($type, $parsed['type']);
        $this->assertSame($domain, $parsed['domain']);
        $this->assertNotSame('', trim((string) $parsed['data']), 'no human-readable summary');
    }

    /**
     * The summary is what an operator reads in the poll-queue view, so the
     * detail that makes each message actionable has to survive into it.
     */
    #[DataProvider('samples')]
    public function testSummaryCarriesTheUsefulDetail(string $extension, string $title, string $type, string $domain): void {
        $this->transport->queue(self::response($extension, $title));

        $session = new Session($this->nic);
        $session->poll(false, 'req');
        $parsed = (new \ReflectionMethod(Session::class, 'parsePollReq'))->invoke($session);

        $expected = match ($type) {
            // which name was asked for, next to the one actually created
            'remappedIdnData' => 'xn--caff-8oa.it',
            // the id that ties the refund back to the bulk operation
            'refundRenewsForBulkTransferMsgData' => 'BT-2026-0001',
            // which namespace to move off
            'wrongNamespaceReminder' => 'extdom-1.0',
        };

        $this->assertStringContainsString($expected, $parsed['data']);
    }
}
