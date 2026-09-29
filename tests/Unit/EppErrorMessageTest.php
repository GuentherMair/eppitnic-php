<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * An error found before the registry was asked carries no EPP code; one the
 * registry answered does.
 */
final class EppErrorMessageTest extends EppTestCase
{
    public function testALocalErrorIsJustItsMessage(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->update());

        $this->assertSame('Operation not allowed, fetch a domain first!', $domain->getError());
    }

    public function testARegistryErrorNamesItsCode(): void {
        $this->transport->queue(<<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
          <response>
            <result code="2304"><msg lang="en">Object status prohibits operation</msg></result>
            <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
          </response>
        </epp>
        XML);
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->delete('example-one.it'));

        $this->assertStringContainsString("EPP code '2304': Object status prohibits operation", $domain->getError());
    }
}
