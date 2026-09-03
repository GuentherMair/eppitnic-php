<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Persistence\SerializedColumn;
use PHPUnit\Framework\TestCase;

/**
 * Both shapes the `domains`/`contacts` array columns are stored in. The
 * envelope case is the one that mattered: a bare unserialize() answers false
 * to it, which is not an array and breaks every caller downstream.
 */
final class SerializedColumnTest extends TestCase
{
    /** what 6.x wrote: base64 of serialize(), behind the marker */
    private static function wrapped(array $value): string {
        return '__SERIALIZED:' . base64_encode(serialize($value));
    }

    public function testDecodesThe6xEnvelope(): void {
        $ns = ['dns1.example.it' => ['name' => 'dns1.example.it']];

        $this->assertSame($ns, SerializedColumn::toArray(self::wrapped($ns)));
    }

    public function testDecodesAPlainSerializedValue(): void {
        $status = ['ok'];

        $this->assertSame($status, SerializedColumn::toArray(serialize($status)));
    }

    public function testABareUnserializeWouldHaveFailedOnTheEnvelope(): void {
        // the bug this class exists for, pinned so it cannot come back
        $wrapped = self::wrapped(['ok']);

        $this->assertFalse(@unserialize($wrapped));
        $this->assertSame(['ok'], SerializedColumn::toArray($wrapped));
    }

    /**
     * @param string|null $stored
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyOrBroken')]
    public function testAnythingUndecodableIsAnEmptyArray(?string $stored): void {
        $this->assertSame([], SerializedColumn::toArray($stored));
    }

    /** @return array<string, array{0: string|null}> */
    public static function emptyOrBroken(): array {
        return [
            'null'               => [null],
            'empty string'       => [''],
            'not serialized'     => ['dns1.example.it'],
            'envelope, bad b64'  => ['__SERIALIZED:!!!not base64!!!'],
            'envelope, bad body' => ['__SERIALIZED:' . base64_encode('not serialized')],
            'serialized scalar'  => [serialize('a string, not an array')],
        ];
    }
}
