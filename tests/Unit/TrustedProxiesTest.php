<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\TrustedProxies;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

final class TrustedProxiesTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + ['trusted_proxies' => ['10.0.0.0/8']]);
    }

    public function testNormalizeCanonicalizesAndDeduplicates(): void {
        $this->assertSame(
            ['172.18.0.1/32', '10.0.0.0/8', '2001:db8::/32'],
            TrustedProxies::normalize(['172.18.0.1', '10.1.2.3/8', '10.0.0.0/8', '2001:db8::/32'])
        );
    }

    public function testNormalizeRejectsSomethingThatIsNotANetwork(): void {
        $this->expectException(\InvalidArgumentException::class);
        TrustedProxies::normalize(['proxy.example.com']);
    }

    public function testNormalizeRejectsANonString(): void {
        $this->expectException(\InvalidArgumentException::class);
        TrustedProxies::normalize([['10.0.0.0/8']]);
    }

    public function testNormalizeRejectsAnIpv4CatchAll(): void {
        $this->expectException(\InvalidArgumentException::class);
        TrustedProxies::normalize(['0.0.0.0/0']);
    }

    public function testNormalizeRejectsAnIpv6CatchAll(): void {
        $this->expectException(\InvalidArgumentException::class);
        TrustedProxies::normalize(['::/0']);
    }

    public function testSetReplacesTheListAndRecordsHistory(): void {
        $stored = TrustedProxies::set(['192.168.1.10'], 3);

        $this->assertSame(['192.168.1.10/32'], $stored);
        $this->assertSame(['192.168.1.10/32'], TrustedProxies::get());

        $row = R::getRow("SELECT * FROM history WHERE object = 'trusted_proxies'");
        $this->assertSame('3', (string) $row['user_id']);
        $this->assertSame(['192.168.1.10/32'], json_decode($row['data'], true)['trusted_proxies']);
    }

    public function testSetAnEmptyListClearsIt(): void {
        $this->assertSame([], TrustedProxies::set([], 3));
        $this->assertSame([], TrustedProxies::get());
    }

    public function testAFailedSetWritesNothing(): void {
        try {
            TrustedProxies::set(['0.0.0.0/0'], 3);
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(['10.0.0.0/8'], TrustedProxies::get());
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'trusted_proxies'"));
    }
}
