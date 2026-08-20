<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\Scenario\DomainLifecycle;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Bringing a domain's nameservers to a target set.
 *
 * The regression this exists for: the update used to remove one name and add
 * another, diffed against whatever fetch() had found. nic.it does not report a
 * nameserver until it has passed the registry's DNS checks, so fetch() finds
 * none for a delegation that does not resolve -- and against an empty set that
 * pair leaves exactly one nameserver, which the registry refuses with 9005,
 * "Too few name servers". Stating the whole target instead cannot do that.
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
     * No wait unless a real domain was named. A generated name can never
     * resolve, so there is nothing for the registry to verify and nothing to
     * wait for -- a pause there would be ten seconds spent on a foregone
     * conclusion, twice per run.
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
     * Re-adding a nameserver that is already there must be silent.
     *
     * Domain::addNS() compared the new addresses against `$this->ns[$name]['ip']`,
     * a key that does not exist for a glueless nameserver -- the ordinary case,
     * since glue is only needed below the domain itself. So the second add
     * warned twice and passed null to foreach(). The suite runs with
     * failOnWarning, so this is asserted by not blowing up.
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
