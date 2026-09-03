<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Api\ClientIp;
use Eppitnic\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which address a request is attributed to, and which network it is in. Both
 * are load-bearing: `safe_networks` skips MFA for what it recognises and the
 * rate limit counts per network, so an answer the client can choose gives away
 * both.
 */
final class ClientIpTest extends TestCase
{
    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        Config::reset();
        parent::tearDown();
    }

    /**
     * @param array<int, string> $trusted
     */
    private function request(?string $remote, ?string $forwardedFor = null, array $trusted = []): void {
        Config::loadForTesting(['trusted_proxies' => $trusted]);

        if ($remote === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $remote;
        }
        if ($forwardedFor === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
    }

    // ---------------------------------------------------------------
    // whose address it is
    // ---------------------------------------------------------------

    public function testTheConnectingAddressIsUsedWhenThereIsNoProxy(): void {
        $this->request('203.0.113.5');

        $this->assertSame('203.0.113.5', ClientIp::get());
    }

    /**
     * The fault this exists for: X-Forwarded-For is written by whoever sends
     * the request, so believing it lets anyone claim a safe_network and skip
     * MFA, or spread a password guess across as many "networks" as they invent.
     */
    public function testAForwardedHeaderFromAnUntrustedPeerIsIgnored(): void {
        $this->request('203.0.113.5', '127.0.0.1');

        $this->assertSame('203.0.113.5', ClientIp::get(), 'a spoofed X-Forwarded-For was believed');
    }

    public function testAForwardedHeaderFromATrustedProxyIsUsed(): void {
        $this->request('10.0.0.1', '203.0.113.5', ['10.0.0.0/8']);

        $this->assertSame('203.0.113.5', ClientIp::get());
    }

    /**
     * With a chain, the rightmost address that is not itself a trusted proxy is
     * the furthest a trusted hop saw. Everything left of it was written by
     * someone with no reason to be believed.
     */
    public function testTheChainIsWalkedFromTheRight(): void {
        $this->request('10.0.0.1', '1.1.1.1, 203.0.113.5, 10.0.0.2', ['10.0.0.0/8']);

        $this->assertSame('203.0.113.5', ClientIp::get());
    }

    public function testNoAddressAtAllIsNull(): void {
        $this->request(null);

        $this->assertNull(ClientIp::get());
    }

    public function testAMalformedAddressIsNull(): void {
        $this->request('not-an-address');

        $this->assertNull(ClientIp::get());
    }

    public function testAnIpv6PeerIsReturned(): void {
        $this->request('2001:db8:1:2::5');

        $this->assertSame('2001:db8:1:2::5', ClientIp::get());
    }

    // ---------------------------------------------------------------
    // prefix matching
    // ---------------------------------------------------------------

    /**
     * @param bool $expected whether $ip is inside $cidr
     */
    #[DataProvider('cidrCases')]
    public function testMatches(string $ip, string $cidr, bool $expected): void {
        $this->assertSame($expected, ClientIp::matches($ip, $cidr));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cidrCases(): array {
        return [
            'v4 loopback exact'     => ['127.0.0.1', '127.0.0.1/32', true],
            'v4 loopback bare'      => ['127.0.0.1', '127.0.0.1', true],
            'v4 inside /8'          => ['10.1.2.3', '10.0.0.0/8', true],
            'v4 outside /8'         => ['11.1.2.3', '10.0.0.0/8', false],
            'v4 inside /24'         => ['203.0.113.200', '203.0.113.0/24', true],
            'v4 just outside /24'   => ['203.0.114.1', '203.0.113.0/24', false],
            'v4 /0 matches all'     => ['8.8.8.8', '0.0.0.0/0', true],
            'v4 /31 boundary'       => ['192.0.2.3', '192.0.2.2/31', true],
            'v4 /31 boundary miss'  => ['192.0.2.4', '192.0.2.2/31', false],

            'v6 loopback'           => ['::1', '::1/128', true],
            'v6 inside /64'         => ['2001:db8:1:2::dead', '2001:db8:1:2::/64', true],
            'v6 outside /64'        => ['2001:db8:1:3::dead', '2001:db8:1:2::/64', false],
            'v6 inside /32'         => ['2001:db8:ffff::1', '2001:db8::/32', true],
            'v6 /0 matches all'     => ['2001:db8::1', '::/0', true],

            // an address is never inside a range of the other family, however
            // similar they look written down
            'v4 against v6 range'   => ['127.0.0.1', '::1/128', false],
            'v6 against v4 range'   => ['::1', '127.0.0.1/32', false],

            'malformed address'     => ['nonsense', '10.0.0.0/8', false],
            'malformed range'       => ['10.0.0.1', 'nonsense/8', false],
            'prefix out of range'   => ['10.0.0.1', '10.0.0.0/33', false],
        ];
    }

    // ---------------------------------------------------------------
    // networks
    // ---------------------------------------------------------------

    /**
     * @param string|null $expected the network $ip belongs to
     */
    #[DataProvider('networkCases')]
    public function testNetwork(string $ip, ?string $expected): void {
        $this->assertSame($expected, ClientIp::network($ip, 24, 64));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function networkCases(): array {
        return [
            'v4 host bits dropped'  => ['203.0.113.200', '203.0.113.0/24'],
            'v4 already a network'  => ['203.0.113.0', '203.0.113.0/24'],
            'v6 host bits dropped'  => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'],
            'v6 already a network'  => ['2001:db8:1:2::', '2001:db8:1:2::/64'],
            'not an address'        => ['nonsense', null],
        ];
    }

    /**
     * The whole point of masking: every address an attacker holds inside one
     * allocation lands in the same bucket. An IPv6 end site is a /64, so a
     * per-address limit would have 18 billion billion buckets to spend.
     */
    public function testAllAddressesInAnIpv6EndSiteShareOneNetwork(): void {
        $first = ClientIp::network('2001:db8:1:2::1', 24, 64);

        foreach (['2001:db8:1:2::2', '2001:db8:1:2:ffff:ffff:ffff:ffff', '2001:db8:1:2:1234::abcd'] as $ip) {
            $this->assertSame($first, ClientIp::network($ip, 24, 64), "{$ip} landed in a different bucket");
        }
    }

    public function testNeighbouringNetworksAreDistinct(): void {
        $this->assertNotSame(
            ClientIp::network('203.0.113.1', 24, 64),
            ClientIp::network('203.0.114.1', 24, 64)
        );
        $this->assertNotSame(
            ClientIp::network('2001:db8:1:2::1', 24, 64),
            ClientIp::network('2001:db8:1:3::1', 24, 64)
        );
    }

    /**
     * inCidr() is what safe_networks is read through, so it has to agree with
     * matches() about the address get() settled on.
     */
    public function testInCidrUsesTheAttributedAddress(): void {
        $this->request('203.0.113.5', '127.0.0.1');

        $this->assertFalse(ClientIp::inCidr('127.0.0.1/32'), 'a spoofed header reached safe_networks');
        $this->assertTrue(ClientIp::inCidr('203.0.113.0/24'));
    }
}
