<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\PowerDnsNameservers;
use PHPUnit\Framework\TestCase;

/**
 * `pdns.nameservers` entry validation -- hostnames only (IPv4/IPv6
 * refused), normalized (lowercased, trailing dot stripped), deduplicated
 * after normalizing, capped at 6.
 */
final class PowerDnsNameserversTest extends TestCase
{
    public function testAValidHostnameIsAccepted(): void {
        $this->assertSame(['ns1.example.it'], PowerDnsNameservers::validate(['ns1.example.it']));
    }

    public function testUppercaseAndTrailingDotAreNormalized(): void {
        $this->assertSame(['ns1.example.it'], PowerDnsNameservers::validate(['NS1.Example.IT.']));
    }

    public function testWhitespaceIsTrimmed(): void {
        $this->assertSame(['ns1.example.it'], PowerDnsNameservers::validate([' ns1.example.it ']));
    }

    public function testABareLabelWithNoDotIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['localhost']);
    }

    public function testAnIpv4AddressIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['192.0.2.1']);
    }

    public function testAnIpv6AddressIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['::1']);
    }

    public function testABlankEntryIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['   ']);
    }

    public function testANonStringEntryIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate([['not' => 'a string']]);
    }

    public function testADuplicateIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['ns1.example.it', 'ns1.example.it']);
    }

    /** duplicates are compared after normalizing, not as given */
    public function testADuplicateAfterNormalizingIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate(['ns1.example.it', 'NS1.example.it.']);
    }

    public function testUpToSixHostsAreAccepted(): void {
        $list = [];
        for ($i = 1; $i <= PowerDnsNameservers::MAX_SERVERS; $i++) {
            $list[] = "ns{$i}.example.it";
        }

        $this->assertCount(PowerDnsNameservers::MAX_SERVERS, PowerDnsNameservers::validate($list));
    }

    public function testASeventhHostIsRejected(): void {
        $list = [];
        for ($i = 1; $i <= PowerDnsNameservers::MAX_SERVERS + 1; $i++) {
            $list[] = "ns{$i}.example.it";
        }

        $this->expectException(\InvalidArgumentException::class);
        PowerDnsNameservers::validate($list);
    }

    public function testAnEmptyListIsAccepted(): void {
        $this->assertSame([], PowerDnsNameservers::validate([]));
    }

    public function testNormalizeLowercasesAndStripsTheTrailingDot(): void {
        $this->assertSame('ns1.example.it', PowerDnsNameservers::normalize('NS1.Example.IT.'));
    }
}
