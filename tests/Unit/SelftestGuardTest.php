<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Selftest\Guard;
use Eppitnic\Selftest\RefusedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one thing in the self-test that must never be wrong.
 *
 * Everything else it does is disposable by design. This is what keeps it that
 * way: the run registers, alters and deletes real objects, and against
 * production those are somebody's domains and somebody's invoice.
 */
final class SelftestGuardTest extends TestCase
{
    private function configured(mixed $epp): void {
        Config::loadForTesting(['epp' => $epp]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // which endpoints are allowed
    // ---------------------------------------------------------------

    public function testTheTestRegistryIsAllowed(): void {
        $this->configured(['server' => 'https://epp.pubtest.nic.it']);

        $this->assertSame('https://epp.pubtest.nic.it', Guard::assertTestEnvironment());
    }

    /**
     * The shipped default in config/mariadb-schema.sql. A deployment that has
     * never been reconfigured is a production deployment.
     */
    public function testProductionIsRefused(): void {
        $this->configured(['server' => 'https://epp.nic.it']);

        $this->expectException(RefusedError::class);
        Guard::assertTestEnvironment();
    }

    /**
     * Why the check parses the URL instead of searching it: every one of these
     * contains the test host as a substring and none of them is the test host.
     *
     * @param string $url an endpoint that must not be accepted
     */
    #[DataProvider('impostors')]
    public function testAnEndpointThatMerelyMentionsTheTestHostIsRefused(string $url): void {
        $this->configured(['server' => $url]);

        $this->expectException(RefusedError::class);
        Guard::assertTestEnvironment();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function impostors(): array {
        return [
            'in the query string' => ['https://epp.nic.it/?see=epp.pubtest.nic.it'],
            'in the path'         => ['https://evil.example/epp.pubtest.nic.it'],
            'as a userinfo part'  => ['https://epp.pubtest.nic.it@epp.nic.it/'],
            'as a subdomain'      => ['https://epp.pubtest.nic.it.evil.example'],
        ];
    }

    /**
     * A hostname with no scheme is a shape somebody may well have typed into
     * the setting, and it names the same host.
     */
    public function testABareHostnameIsUnderstood(): void {
        $this->configured(['server' => 'epp.pubtest.nic.it']);

        $this->assertSame('epp.pubtest.nic.it', Guard::assertTestEnvironment());
    }

    // ---------------------------------------------------------------
    // nothing configured
    // ---------------------------------------------------------------

    public function testAnEmptyEndpointIsRefused(): void {
        $this->configured(['server' => '']);

        $this->expectException(RefusedError::class);
        Guard::assertTestEnvironment();
    }

    public function testAMissingServerKeyIsRefused(): void {
        $this->configured(['username' => 'someone']);

        $this->expectException(RefusedError::class);
        Guard::assertTestEnvironment();
    }

    /**
     * A malformed `epp` setting must refuse, not crash and not pass. This is
     * the branch that decides whether anything reaches the network.
     */
    public function testASettingThatIsNotAnArrayIsRefused(): void {
        $this->configured('https://epp.pubtest.nic.it');

        $this->expectException(RefusedError::class);
        Guard::assertTestEnvironment();
    }

    /**
     * The refusal has to say what was configured and what would be acceptable:
     * the reader's next action is to change one into the other.
     */
    public function testTheRefusalNamesBothEndpoints(): void {
        $this->configured(['server' => 'https://epp.nic.it']);

        try {
            Guard::assertTestEnvironment();
            $this->fail('production was not refused');
        } catch (RefusedError $e) {
            $this->assertStringContainsString('epp.nic.it', $e->getMessage());
            $this->assertStringContainsString('epp.pubtest.nic.it', $e->getMessage());
        }
    }

    public function testIsTestEndpointAgreesWithTheAssertion(): void {
        $this->assertTrue(Guard::isTestEndpoint('https://epp.pubtest.nic.it'));
        $this->assertFalse(Guard::isTestEndpoint('https://epp.nic.it'));
        $this->assertFalse(Guard::isTestEndpoint(''));
    }
}
