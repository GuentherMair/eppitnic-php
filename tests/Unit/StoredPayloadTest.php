<?php

namespace Net\EPP\Tests\Unit;

use Net\EPP\StoredPayload;
use PHPUnit\Framework\TestCase;

/**
 * Reading EPP bodies out of `responses` / `msgqueue`, written by either
 * generation of the codebase.
 */
final class StoredPayloadTest extends TestCase
{
    private const XML = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response/></epp>';

    /**
     * What 6.x wrote: base64 of a serialized string, behind a marker.
     */
    private static function wrap(string $body): string {
        return '__SERIALIZED:' . base64_encode(serialize($body));
    }

    public function testALegacyRowDecodesToItsBody(): void {
        $this->assertSame(self::XML, StoredPayload::decode(self::wrap(self::XML)));
    }

    public function testACurrentRowIsReturnedAsItIs(): void {
        $this->assertSame(self::XML, StoredPayload::decode(self::XML));
    }

    /**
     * A body that happens to contain the marker somewhere inside it is not
     * wrapped -- only one at the very start is an envelope.
     */
    public function testTheMarkerOnlyCountsAtTheStart(): void {
        $body = '<epp><msg>__SERIALIZED:not really</msg></epp>';

        $this->assertSame($body, StoredPayload::decode($body));
    }

    public function testAnEmptyRowIsAnEmptyBody(): void {
        $this->assertSame('', StoredPayload::decode(''));
    }

    /**
     * A damaged envelope is not an empty body, and must not be reported as one
     * -- an empty body reads as "the registry said nothing", which is a
     * different and wrong conclusion.
     *
     * @param string $stored a row that claims to be wrapped but is not readable
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('damagedEnvelopes')]
    public function testADamagedEnvelopeIsNotABody(string $stored): void {
        $this->assertNull(StoredPayload::decode($stored));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function damagedEnvelopes(): array {
        return [
            'not base64'            => ['__SERIALIZED:!!!!not base64!!!!'],
            'base64 of nothing'     => ['__SERIALIZED:'],
            'base64 of non-serialized' => ['__SERIALIZED:' . base64_encode('plain text')],
            'serialized non-string' => ['__SERIALIZED:' . base64_encode(serialize(['an', 'array']))],
            'truncated'             => [substr('__SERIALIZED:' . base64_encode(serialize(self::XML)), 0, 30)],
        ];
    }

    public function testIsWrappedIdentifiesTheGeneration(): void {
        $this->assertTrue(StoredPayload::isWrapped(self::wrap(self::XML)));
        $this->assertFalse(StoredPayload::isWrapped(self::XML));
    }
}
