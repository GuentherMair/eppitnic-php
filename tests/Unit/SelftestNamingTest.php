<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Selftest\Naming;
use PHPUnit\Framework\TestCase;

/**
 * The names a self-test run gives what it creates. A run must not collide with
 * earlier leftovers, and something always is left; and `selftest reap` must tell
 * from the name alone which run made an object and when.
 */
final class SelftestNamingTest extends TestCase
{
    /** an arbitrary fixed moment, so the expectations can be written down */
    private const AT = 1786000000;

    // ---------------------------------------------------------------
    // what the names look like
    // ---------------------------------------------------------------

    /**
     * EPP's clIDType caps a handle at 16 characters. Contact::generateHandle()
     * spends all 16 on hex, which is why the self-test names its own.
     */
    public function testAHandleFitsInsideTheEppLimit(): void {
        $names = new Naming(self::AT);

        foreach (['R', 'A', 'T', 'D'] as $role) {
            $handle = $names->handle($role, 9);
            $this->assertLessThanOrEqual(16, strlen($handle), "{$handle} is too long for clIDType");
            $this->assertGreaterThanOrEqual(3, strlen($handle));
            $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $handle);
        }
    }

    public function testADomainIsALowercaseItName(): void {
        $this->assertSame('st-tjc6f4-1.it', (new Naming(self::AT))->domain(1));
    }

    /**
     * One stamp per run, shared by everything in it -- not one per object.
     * That is what makes a whole run's leftovers findable together.
     */
    public function testEveryNameInARunCarriesTheSameStamp(): void {
        $names = new Naming(self::AT);

        $this->assertSame(
            [self::AT, self::AT, self::AT],
            [
                Naming::madeAt($names->handle('R', 1)),
                Naming::madeAt($names->handle('T', 2)),
                Naming::domainMadeAt($names->domain(1)),
            ]
        );
    }

    public function testTwoRunsAtDifferentTimesDoNotCollide(): void {
        $earlier = new Naming(self::AT);
        $later   = new Naming(self::AT + 1);

        $this->assertNotSame($earlier->handle('R', 1), $later->handle('R', 1));
        $this->assertNotSame($earlier->domain(1), $later->domain(1));
    }

    public function testRolesAndIndicesAreDistinct(): void {
        $names = new Naming(self::AT);

        $handles = [
            $names->handle('R', 1), $names->handle('R', 2),
            $names->handle('A', 1), $names->handle('T', 1), $names->handle('D', 1),
        ];

        $this->assertSame($handles, array_unique($handles));
    }

    // ---------------------------------------------------------------
    // reading a name back
    // ---------------------------------------------------------------

    public function testTheStampDecodesToTheMomentTheRunStarted(): void {
        $names = new Naming(self::AT);

        $this->assertSame(self::AT, Naming::timeOfStamp($names->stamp));
        $this->assertSame(self::AT, Naming::madeAt($names->handle('R', 1)));
        $this->assertSame(self::AT, Naming::domainMadeAt($names->domain(1)));
    }

    /**
     * `selftest reap` deletes what these match. A handle belonging to somebody
     * else must never be mistaken for one of ours.
     *
     * @param string $handle something that is not a self-test handle
     */
    public function testSomebodyElsesHandleIsNotOurs(): void {
        foreach ([
            'ABCD1234EFGH5678',            // what generateHandle() produces
            'STANDARD1',                   // starts with ST and is not ours
            'ST',
            '',
            'REGISTRANT-1',
        ] as $handle) {
            $this->assertNull(Naming::madeAt($handle), "{$handle} was taken for a self-test handle");
            $this->assertFalse(Naming::isOurs($handle), "{$handle} was taken for a self-test object");
        }
    }

    public function testSomebodyElsesDomainIsNotOurs(): void {
        foreach (['example.it', 'st-something.it', 'st-tjc6f4-1.com', 'store-1.it'] as $domain) {
            $this->assertNull(Naming::domainMadeAt($domain), "{$domain} was taken for a self-test domain");
        }
    }

    /**
     * A stamp that decodes to the future did not come from a run that has
     * happened. Treating it as ripe would let a name nobody here made -- or
     * one written before a clock correction -- be deleted as a leftover.
     */
    public function testAStampFromTheFutureIsNotOurs(): void {
        $ahead = new Naming(time() + 86400);

        $this->assertNull(Naming::madeAt($ahead->handle('R', 1)));
        $this->assertNull(Naming::timeOfStamp($ahead->stamp));
    }

    public function testCaseDoesNotMatterWhenReadingAName(): void {
        $names = new Naming(self::AT);

        $this->assertSame(self::AT, Naming::madeAt(strtolower($names->handle('R', 1))));
        $this->assertSame(self::AT, Naming::domainMadeAt(strtoupper($names->domain(1))));
    }

    public function testADefaultRunIsStampedNow(): void {
        $before = time();
        $names = new Naming();

        $this->assertGreaterThanOrEqual($before, Naming::timeOfStamp($names->stamp));
        $this->assertLessThanOrEqual(time(), Naming::timeOfStamp($names->stamp));
    }
}
