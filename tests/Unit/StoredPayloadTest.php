<?php

namespace Net\EPP\Tests\Unit;

use Net\EPP\Persistence\StoredPayload;
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
            'serialized scalar'     => ['__SERIALIZED:' . base64_encode(serialize(42))],
            'truncated'             => [substr('__SERIALIZED:' . base64_encode(serialize(self::XML)), 0, 30)],
        ];
    }

    /**
     * `sv_httpheaders` was wrapped as an array, not a string: 6.x kept the
     * response headers as a field => value map where current code stores the
     * raw block. Rejecting those as "not a string" left every one of the 532
     * such rows in the live database undecodable.
     */
    public function testAHeaderMapBecomesARawHeaderBlock(): void {
        $stored = '__SERIALIZED:' . base64_encode(serialize([
            'date'         => 'Thu, 28 Oct 2010 06:48:25 GMT',
            'content-type' => 'text/xml;charset=UTF-8',
            'connection'   => 'close',
        ]));

        $this->assertSame(
            "date: Thu, 28 Oct 2010 06:48:25 GMT\r\n"
            . "content-type: text/xml;charset=UTF-8\r\n"
            . "connection: close\r\n",
            StoredPayload::decode($stored)
        );
    }

    /**
     * A repeated field arrived as more than one line and goes back as more
     * than one line.
     */
    public function testARepeatedFieldKeepsItsLines(): void {
        $stored = '__SERIALIZED:' . base64_encode(serialize([
            'set-cookie' => ['a=1; path=/', 'b=2; path=/'],
        ]));

        $this->assertSame(
            "set-cookie: a=1; path=/\r\nset-cookie: b=2; path=/\r\n",
            StoredPayload::decode($stored)
        );
    }

    public function testAnEmptyHeaderMapIsAnEmptyBlock(): void {
        $this->assertSame('', StoredPayload::decode('__SERIALIZED:' . base64_encode(serialize([]))));
    }

    /**
     * A field holding something no header line can carry is not silently
     * flattened -- the row is reported undecodable and left as it is.
     */
    public function testANonScalarFieldIsNotRendered(): void {
        $stored = '__SERIALIZED:' . base64_encode(serialize(['weird' => new \stdClass()]));

        $this->assertNull(StoredPayload::decode($stored));
    }

    public function testIsWrappedIdentifiesTheGeneration(): void {
        $this->assertTrue(StoredPayload::isWrapped(self::wrap(self::XML)));
        $this->assertFalse(StoredPayload::isWrapped(self::XML));
    }
}
