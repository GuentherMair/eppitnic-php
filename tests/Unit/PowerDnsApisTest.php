<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\PowerDnsApis;
use PHPUnit\Framework\TestCase;

/**
 * `pdns.apis` entry validation/redaction/URL-building -- the write-only
 * `api_key` (blank keeps the stored key of the same endpoint), duplicate
 * and oversized-list rejection, and IPv6 URL bracketing.
 */
final class PowerDnsApisTest extends TestCase
{
    private const VALID = ['protocol' => 'https', 'host' => 'ns1.example.com', 'port' => 8081, 'api_key' => 'secret-key'];

    public function testAMinimalValidEntryIsAccepted(): void {
        $result = PowerDnsApis::validate([self::VALID], []);

        $this->assertSame([self::VALID], $result);
    }

    public function testHostAndProtocolAreLowercased(): void {
        $result = PowerDnsApis::validate([['protocol' => 'HTTPS', 'host' => 'NS1.Example.COM', 'port' => 8081, 'api_key' => 'k']], []);

        $this->assertSame('https', $result[0]['protocol']);
        $this->assertSame('ns1.example.com', $result[0]['host']);
    }

    public function testAnIpv4HostIsAccepted(): void {
        $result = PowerDnsApis::validate([['protocol' => 'http', 'host' => '192.0.2.1', 'port' => 8081, 'api_key' => 'k']], []);

        $this->assertSame('192.0.2.1', $result[0]['host']);
    }

    public function testAnIpv6HostIsAccepted(): void {
        $result = PowerDnsApis::validate([['protocol' => 'http', 'host' => '::1', 'port' => 8081, 'api_key' => 'k']], []);

        $this->assertSame('::1', $result[0]['host']);
    }

    public function testABadProtocolIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'ftp', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'k']], []);
    }

    public function testAGarbageHostIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'not a host!', 'port' => 8081, 'api_key' => 'k']], []);
    }

    public function testAZeroPortIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 0, 'api_key' => 'k']], []);
    }

    public function testAnOutOfRangePortIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 65536, 'api_key' => 'k']], []);
    }

    public function testANonNumericPortIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 'abc', 'api_key' => 'k']], []);
    }

    public function testADuplicateEndpointInOneListIsRejected(): void {
        $entry = ['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'k'];
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([$entry, $entry], []);
    }

    public function testADuplicateIsDetectedRegardlessOfHostCase(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([
            ['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'k'],
            ['protocol' => 'http', 'host' => 'NS1', 'port' => 8081, 'api_key' => 'k2'],
        ], []);
    }

    public function testANewEndpointWithNoKeyIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => '']], []);
    }

    public function testABlankKeyKeepsTheStoredKeyOfTheSameEndpoint(): void {
        $stored = [['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'stored-key']];

        $result = PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => '']], $stored);

        $this->assertSame('stored-key', $result[0]['api_key']);
    }

    public function testAWhitespaceKeyIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate([['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'has space']], []);
    }

    public function testUpToSixServersAreAccepted(): void {
        $list = [];
        for ($i = 1; $i <= PowerDnsApis::MAX_SERVERS; $i++) {
            $list[] = ['protocol' => 'http', 'host' => "ns{$i}", 'port' => 8081, 'api_key' => 'k'];
        }

        $result = PowerDnsApis::validate($list, []);

        $this->assertCount(PowerDnsApis::MAX_SERVERS, $result);
    }

    public function testASeventhServerIsRejected(): void {
        $list = [];
        for ($i = 1; $i <= PowerDnsApis::MAX_SERVERS + 1; $i++) {
            $list[] = ['protocol' => 'http', 'host' => "ns{$i}", 'port' => 8081, 'api_key' => 'k'];
        }

        $this->expectException(\InvalidArgumentException::class);
        PowerDnsApis::validate($list, []);
    }

    public function testRedactReplacesTheKeyWithABoolean(): void {
        $redacted = PowerDnsApis::redact([self::VALID]);

        $this->assertSame(
            ['protocol' => 'https', 'host' => 'ns1.example.com', 'port' => 8081, 'api_key_set' => true],
            $redacted[0]
        );
        $this->assertArrayNotHasKey('api_key', $redacted[0]);
    }

    public function testRedactReportsAnUnsetKey(): void {
        $redacted = PowerDnsApis::redact([['protocol' => 'http', 'host' => 'ns1', 'port' => 8081, 'api_key' => '']]);

        $this->assertFalse($redacted[0]['api_key_set']);
    }

    public function testBaseUrlForHttpAndHttps(): void {
        $this->assertSame('http://ns1:8081', PowerDnsApis::baseUrl(['protocol' => 'http', 'host' => 'ns1', 'port' => 8081]));
        $this->assertSame('https://ns1:8443', PowerDnsApis::baseUrl(['protocol' => 'https', 'host' => 'ns1', 'port' => 8443]));
    }

    public function testBaseUrlBracketsAnIpv6Host(): void {
        $this->assertSame('https://[::1]:8081', PowerDnsApis::baseUrl(['protocol' => 'https', 'host' => '::1', 'port' => 8081]));
    }

    public function testBaseUrlDoesNotBracketAnIpv4HostOrHostname(): void {
        $this->assertSame('http://192.0.2.1:8081', PowerDnsApis::baseUrl(['protocol' => 'http', 'host' => '192.0.2.1', 'port' => 8081]));
        $this->assertSame('http://ns1.example.com:8081', PowerDnsApis::baseUrl(['protocol' => 'http', 'host' => 'ns1.example.com', 'port' => 8081]));
    }
}
