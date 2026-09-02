<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Support\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a login password has to look like. There was no such rule: four call
 * sites reach `password_hash()` and none looked at what it was given, so a
 * single character was accepted. First enforced in the installer.
 */
final class PasswordPolicyTest extends TestCase
{
    /**
     * @param string[] $missing the rule descriptions expected to be reported
     */
    #[DataProvider('candidates')]
    public function testViolations(string $password, int $missingCount): void {
        $this->assertCount($missingCount, PasswordPolicy::violations($password));
        $this->assertSame($missingCount === 0, PasswordPolicy::isAcceptable($password));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function candidates(): array {
        return [
            'everything present'   => ['Correct-Horse-42!', 0],
            'empty'                => ['', 5],
            'too short but varied' => ['Ab1!', 1],
            'no upper'             => ['correct-horse-42!', 1],
            'no lower'             => ['CORRECT-HORSE-42!', 1],
            'no digit'             => ['Correct-Horse-xy!', 1],
            'no special'           => ['CorrectHorse4242', 1],
            'letters only, short'  => ['abcdef', 4],
        ];
    }

    /**
     * The rule is derived from the only complexity this codebase already
     * expresses, so what it generates must satisfy what it demands.
     */
    public function testAGeneratedRegistryPasswordSatisfiesThePolicy(): void {
        for ($i = 0; $i < 50; $i++) {
            $password = PasswordGenerator::forRegistry();
            $this->assertSame([], PasswordPolicy::violations($password), "rejected '{$password}'");
        }
    }

    /**
     * Length is deliberately not taken from PasswordGenerator. Sixteen is
     * EPP's ceiling for a registry credential -- a protocol constraint on one
     * field, with no bearing on a password this application hashes itself.
     */
    public function testTheLengthFloorIsNotTheEppCeiling(): void {
        $this->assertLessThan(16, PasswordPolicy::MIN_LENGTH);
        $this->assertTrue(PasswordPolicy::isAcceptable('Abcdefghij1!'));
        $this->assertFalse(PasswordPolicy::isAcceptable('Abcdefghi1!'));
    }

    // ---------------------------------------------------------------
    // saying so, before anything is typed
    // ---------------------------------------------------------------

    /**
     * A rule that can only be checked cannot be explained, and the installer
     * has to show a person what is expected before they type it.
     */
    public function testTheRulesAreAvailableAsData(): void {
        $described = PasswordPolicy::describe();

        $this->assertSame(
            ['length', 'lower', 'upper', 'number', 'special'],
            array_column($described, 'key')
        );
        foreach ($described as $rule) {
            $this->assertNotSame('', $rule['description']);
        }
    }

    /**
     * The advice shown and the violations reported come from one list, so they
     * cannot drift apart.
     */
    public function testEveryRuleCanBeViolatedAndIsDescribedTheSameWay(): void {
        $descriptions = array_column(PasswordPolicy::describe(), 'description');

        foreach (PasswordPolicy::violations('') as $violation) {
            $this->assertContains($violation, $descriptions);
        }
    }

    public function testTheExplanationNamesEveryMissingRule(): void {
        $this->assertSame('', PasswordPolicy::explain('Correct-Horse-42!'));

        $explanation = PasswordPolicy::explain('abc');
        $this->assertStringContainsString('upper-case', $explanation);
        $this->assertStringContainsString('digit', $explanation);
        $this->assertStringEndsWith('.', $explanation);
    }
}
