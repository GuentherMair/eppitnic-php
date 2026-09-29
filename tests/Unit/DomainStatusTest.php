<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/** A domain's status is a list of distinct states. */
final class DomainStatusTest extends EppTestCase
{
    private function fetched(): Domain {
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-one.it'), $domain->getError());
        return $domain;
    }

    /** the fixture carries ok as both a domain status and an extdom ownStatus */
    public function testAStateReportedTwiceIsListedOnce(): void {
        $this->assertSame(['ok'], $this->fetched()->get('status'));
    }

    public function testAddingAStateTwiceListsItOnce(): void {
        $domain = $this->fetched();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->assertTrue($domain->updateStatus('clientHold'));
        $this->assertTrue($domain->updateStatus('clientHold'));

        $this->assertSame(['ok', 'clientHold'], $domain->get('status'));
    }

    /** a list, not a map with a gap, so it stays a JSON array */
    public function testRemovingAStateLeavesAList(): void {
        $domain = $this->fetched();
        foreach (range(1, 3) as $_) {
            $this->transport->queue(CommandCatalog::OK_RESPONSE);
        }

        $this->assertTrue($domain->updateStatus('clientHold'));
        $this->assertTrue($domain->updateStatus('clientUpdateProhibited'));
        $this->assertTrue($domain->updateStatus('clientHold', 'rem'));

        $this->assertSame(['ok', 'clientUpdateProhibited'], $domain->get('status'));
    }
}
