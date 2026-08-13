<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\PasswordService;
use PHPUnit\Framework\TestCase;

/**
 * Generated credentials.
 *
 * A generator is hard to test directly -- the output is meant to be
 * unpredictable -- so these assert the properties that hold for every draw,
 * over enough draws that a systematic fault shows up.
 */
final class PasswordServiceTest extends TestCase
{
    /** enough draws to catch a class requirement that is only usually met */
    private const DRAWS = 400;

    public function testItIsTheRequestedLength(): void {
        foreach ([6, 12, 16, 32] as $length) {
            $this->assertSame($length, strlen((new PasswordService($length))->get()));
        }
    }

    public function testEveryCharacterComesFromTheChosenSet(): void {
        $generator = new PasswordService(16, true);

        for ($i = 0; $i < self::DRAWS; $i++) {
            $this->assertSame(
                '',
                trim((string) preg_replace('/[' . preg_quote(PasswordService::SAFE_CHARSET, '/') . ']/', '', $generator->get())),
                'a character outside the safe set was drawn'
            );
        }
    }

    /**
     * The safe set is the one a person has to read off a screen, so the
     * characters that get misread must not be in it.
     */
    public function testTheSafeSetExcludesTheAmbiguousCharacters(): void {
        foreach (['l', 'I', 'O', '0'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, PasswordService::SAFE_CHARSET);
        }
        foreach (['$', '%', '!'] as $awkward) {
            $this->assertStringNotContainsString($awkward, PasswordService::SAFE_CHARSET);
        }
    }

    public function testTheRequiredClassesAreAlwaysPresent(): void {
        $generator = new PasswordService(16, true, requireUpper: true, requireLower: true, requireNumber: true, requireSpecialChar: true);

        for ($i = 0; $i < self::DRAWS; $i++) {
            $password = $generator->get();

            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
        }
    }

    /**
     * A class that was not asked for is not forced in either -- otherwise
     * "no digits" would be unsatisfiable rather than merely not required.
     */
    public function testAnUnrequiredClassIsNotForced(): void {
        $generator = new PasswordService(8, false, requireUpper: false, requireLower: true, requireNumber: false, requireSpecialChar: false);

        $sawSomethingOtherThanLower = false;
        for ($i = 0; $i < self::DRAWS; $i++) {
            if (preg_match('/[^a-z]/', $generator->get()) === 1) {
                $sawSomethingOtherThanLower = true;
                break;
            }
        }

        $this->assertTrue($sawSomethingOtherThanLower, 'the draw was restricted to the required class');
    }

    /**
     * Requirements the character set cannot meet would be an endless loop of
     * drawing and discarding, so they are refused up front.
     */
    public function testASetThatCannotMeetTheRequirementsIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordService(16, requireNumber: true, charset: 'abcdefgh');
    }

    /**
     * The other way it cannot be met: more classes required than there are
     * characters to hold them.
     */
    public function testAPasswordTooShortForItsRequirementsIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordService(2, true, requireUpper: true, requireLower: true, requireNumber: true, requireSpecialChar: true);
    }

    public function testAnEmptySetIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordService(16, requireUpper: false, requireLower: false, requireSpecialChar: false, charset: '');
    }

    /**
     * A caller's own set is honoured.
     */
    public function testACustomSetIsUsed(): void {
        $generator = new PasswordService(20, requireUpper: false, requireLower: true, requireSpecialChar: false, charset: 'abc');

        $this->assertMatchesRegularExpression('/^[abc]{20}$/', $generator->get());
    }

    public function testARegistryPasswordFitsWhatEppAccepts(): void {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $password = PasswordService::forRegistry();

            // pwType: token, minLength 6, maxLength 16
            $this->assertSame(16, strlen($password));
            $this->assertDoesNotMatchRegularExpression('/\s/', $password, 'whitespace does not survive an EPP token');

            // and nothing that would need escaping to reach the registry
            $this->assertDoesNotMatchRegularExpression('/[<>&\'"]/', $password);

            // every class, so that an unpublished complexity policy cannot
            // refuse it -- see the note on forRegistry()
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
        }
    }

    /**
     * Not a randomness test -- it cannot be one -- but a draw that repeats, or
     * that never uses most of its alphabet, is broken in a way worth catching.
     */
    public function testDrawsDifferAndUseTheAlphabet(): void {
        $seen = [];
        $characters = [];

        for ($i = 0; $i < self::DRAWS; $i++) {
            $password = PasswordService::forRegistry();
            $seen[$password] = true;
            foreach (str_split($password) as $character) {
                $characters[$character] = true;
            }
        }

        $this->assertCount(self::DRAWS, $seen, 'a password repeated');
        $this->assertSame(
            strlen(PasswordService::SAFE_CHARSET),
            count($characters),
            'some characters of the set were never drawn'
        );
    }
}
