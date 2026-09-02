<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\Scenario\DomainLifecycle;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Bringing a domain's nameservers to a target set. The update used to remove one
 * and add another against what fetch() found -- which is nothing for a
 * delegation that does not resolve, leaving one nameserver, refused as 9005.
 */
final class SelftestNameserverTest extends EppTestCase
{
    private const TARGET = ['ns1.example.it', 'ns3.example.it'];

    /**
     * @param string[] $held what the registry reported
     */
    private function reconciled(array $held): array {
        $domain = new Domain($this->nic);
        $domain->set('domain', 'st-tjc6f4-1.it');
        foreach ($held as $nameserver) {
            $domain->addNS($nameserver);
        }

        $method = new \ReflectionMethod(DomainLifecycle::class, 'reconcileNameservers');
        $method->invoke(null, $domain, self::TARGET);

        return array_keys((array) $domain->get('ns'));
    }

    /**
     * The case that failed live.
     */
    public function testAnEmptyReportStillEndsWithTwoNameservers(): void {
        $result = $this->reconciled([]);

        sort($result);
        $this->assertSame(self::TARGET, $result, 'the registry would refuse this as too few');
        $this->assertCount(2, $result);
    }

    public function testTheSecondIsSwappedForTheThirdWhenAllAreReported(): void {
        $result = $this->reconciled(['ns1.example.it', 'ns2.example.it']);

        sort($result);
        $this->assertSame(self::TARGET, $result);
    }

    public function testAnAlreadyCorrectSetIsLeftAsItIs(): void {
        $result = $this->reconciled(self::TARGET);

        sort($result);
        $this->assertSame(self::TARGET, $result);
    }

    /**
     * Whatever the registry says it holds, the answer is the target -- there
     * is no reported state that produces fewer than two.
     */
    public function testNoReportedStateEndsBelowTheMinimum(): void {
        foreach ([
            [],
            ['ns1.example.it'],
            ['ns9.example.it'],
            ['ns1.example.it', 'ns2.example.it', 'ns9.example.it'],
            ['ns1.example.it', 'ns3.example.it'],
        ] as $held) {
            $result = $this->reconciled($held);

            $this->assertGreaterThanOrEqual(2, count($result), 'ended below the registry minimum');
            sort($result);
            $this->assertSame(self::TARGET, $result);
        }
    }

    /**
     * No wait unless a real domain was named: a generated name can never resolve,
     * so there is nothing to verify and nothing to wait for -- a pause there is
     * ten seconds on a foregone conclusion, twice per run.
     */
    public function testNoVerificationWaitWithoutASuppliedDomain(): void {
        $scenario = new DomainLifecycle(['ns1.example.it', 'ns2.example.it'], null, 0);
        $run = new \Eppitnic\Selftest\Run($this->nic, new \Eppitnic\Selftest\Naming(1786000000));

        $method = new \ReflectionMethod(DomainLifecycle::class, 'awaitVerification');
        $started = microtime(true);
        $method->invoke($scenario, $run, 'st-tjc6f4-1.it');

        $this->assertSame([], $run->steps(), 'a wait was recorded with no domain to wait for');
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function testNameserversNotWantedAreRemoved(): void {
        $this->assertNotContains('ns2.example.it', $this->reconciled(['ns1.example.it', 'ns2.example.it']));
    }

    /**
     * Re-adding a nameserver already there must be silent: addNS() compared
     * against `$this->ns[$name]['ip']`, absent for a glueless one, so the second
     * add warned twice. failOnWarning is what asserts it.
     */
    public function testReAddingAGluelessNameserverIsSilent(): void {
        $domain = new Domain($this->nic);
        $domain->addNS('ns1.example.it');
        $domain->addNS('ns1.example.it');

        $this->assertSame(['ns1.example.it'], array_keys((array) $domain->get('ns')));
    }

    public function testAGluedNameserverStillNoticesANewAddress(): void {
        $domain = new Domain($this->nic);
        $domain->addNS('ns1.st-tjc6f4-1.it', ['192.0.2.1']);
        $domain->addNS('ns1.st-tjc6f4-1.it', ['192.0.2.2']);

        $addresses = array_column((array) $domain->get('ns')['ns1.st-tjc6f4-1.it']['ip'], 'address');
        $this->assertContains('192.0.2.2', $addresses, 'a changed address was not picked up');
    }
}
