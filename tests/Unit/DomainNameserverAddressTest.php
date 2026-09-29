<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Glue addresses are validated as addresses -- no reverse lookup -- before
 * the nameserver list is touched.
 */
final class DomainNameserverAddressTest extends EppTestCase
{
    private function addresses(Domain $domain, string $name): array {
        return array_column((array) ($domain->get('ns')[$name]['ip'] ?? []), 'type', 'address');
    }

    public function testFamiliesComeFromTheAddressNotAFullStop(): void {
        $domain = new Domain($this->nic);

        $domain->addNS('ns1.example.it', ['192.0.2.1', '2001:db8::1']);
        $domain->addNS('ns2.example.it', ['::ffff:192.0.2.9']);

        $this->assertSame(['192.0.2.1' => 'v4', '2001:db8::1' => 'v6'], $this->addresses($domain, 'ns1.example.it'));
        $this->assertSame(['::ffff:192.0.2.9' => 'v6'], $this->addresses($domain, 'ns2.example.it'));
    }

    public function testAnInvalidAddressLeavesNothingHalfAdded(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->addNS('ns1.example.it', ['192.0.2.1', 'not-an-ip']));

        $this->assertStringContainsString("'not-an-ip' is not a valid", $domain->getError());
        $this->assertSame([], (array) $domain->get('ns'));
        $this->assertFalse($domain->hasChanges());
    }

    public function testAnInvalidReplacementKeepsTheNameserverAsItWas(): void {
        $domain = new Domain($this->nic);
        $domain->addNS('ns1.example.it', ['192.0.2.1']);

        $this->assertFalse($domain->addNS('ns1.example.it', ['999.1.1.1']));

        $this->assertSame(['192.0.2.1' => 'v4'], $this->addresses($domain, 'ns1.example.it'));
    }

    public function testMoreThanTwoAddressesAreRefused(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->addNS('ns1.example.it', ['192.0.2.1', '192.0.2.2', '192.0.2.3']));
        $this->assertSame([], (array) $domain->get('ns'));
    }

    public function testChangedAddressesReplaceTheOldOnesAndUnchangedOnesStay(): void {
        $domain = new Domain($this->nic);
        $domain->addNS('ns1.example.it', ['192.0.2.1', '192.0.2.2']);

        $domain->addNS('ns1.example.it', ['192.0.2.2', '192.0.2.1']);
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_keys($this->addresses($domain, 'ns1.example.it')));

        $domain->addNS('ns1.example.it', ['192.0.2.3']);
        $this->assertSame(['192.0.2.3' => 'v4'], $this->addresses($domain, 'ns1.example.it'));

        $domain->addNS('ns1.example.it');
        $this->assertSame(['192.0.2.3' => 'v4'], $this->addresses($domain, 'ns1.example.it'), 'no addresses given keeps them');
    }

    public function testAnAlreadyPunycodeNameIsAccepted(): void {
        $domain = new Domain($this->nic);

        $this->assertSame('ns1.xn--caff-8oa.it', $domain->addNS('ns1.xn--caff-8oa.it'));
        $this->assertSame('ns1.xn--caff-8oa.it', $domain->addNS("ns1.caff\u{00e8}.it"));
        $this->assertSame(['ns1.xn--caff-8oa.it'], array_keys((array) $domain->get('ns')));
    }
}
