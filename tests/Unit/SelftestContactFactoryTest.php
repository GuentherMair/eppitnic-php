<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Selftest\ContactFactory;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * The contacts a self-test run creates have to be ones nic.it will accept.
 *
 * Their details are invented, but the registry validates the extcon registrant
 * block against rules that are not in any schema, so "plausible" is not enough
 * -- a self-test that cannot get past `contact create` tests nothing at all.
 */
final class SelftestContactFactoryTest extends EppTestCase
{
    private function filled(bool $isRegistrant): Contact {
        $contact = new Contact($this->nic);
        ContactFactory::fill($contact, 'STAAAAAAR1', $isRegistrant);

        return $contact;
    }

    // ---------------------------------------------------------------
    // the consent rule
    // ---------------------------------------------------------------

    /**
     * The rule this exists for. nic.it refuses a company registrant that
     * withholds consent to publication -- 2308, extended reason 8028,
     * "consentForPublishing cannot be set to false if entity type != 1 and
     * entity type != 3" -- because anyone other than a natural person or a
     * freelancer is published by law.
     */
    public function testACompanyRegistrantConsentsToPublication(): void {
        $contact = $this->filled(true);

        $this->assertSame(ContactFactory::ENTITY_COMPANY, (int) $contact->get('entitytype'));
        $this->assertSame(1, (int) $contact->get('consentforpublishing'), 'the registry would refuse this contact');
    }

    /**
     * An admin or tech contact carries no registrant block for the rule to
     * apply to, so it can stay out of the whois.
     */
    public function testANonRegistrantKeepsItsDetailsUnpublished(): void {
        $contact = $this->filled(false);

        $this->assertSame(ContactFactory::ENTITY_NONE, (int) $contact->get('entitytype'));
        $this->assertSame(0, (int) $contact->get('consentforpublishing'));
    }

    public function testOnlyNaturalPersonsAndFreelancersMayWithholdConsent(): void {
        $this->assertTrue(ContactFactory::mayWithholdConsent(1), 'a natural person may');
        $this->assertTrue(ContactFactory::mayWithholdConsent(3), 'a freelancer may');
        $this->assertTrue(ContactFactory::mayWithholdConsent(ContactFactory::ENTITY_NONE), 'a non-registrant has no block');

        foreach ([2, 4, 5, 6, 7] as $entityType) {
            $this->assertFalse(
                ContactFactory::mayWithholdConsent($entityType),
                "entity type {$entityType} must consent to publication"
            );
        }
    }

    // ---------------------------------------------------------------
    // the rest of the registrant block
    // ---------------------------------------------------------------

    public function testARegistrantCarriesTheFieldsExtconRequires(): void {
        $contact = $this->filled(true);

        $this->assertSame('IT', $contact->get('nationalitycode'));
        $this->assertNotSame('', $contact->get('regcode'));
    }

    // ---------------------------------------------------------------
    // the registration code
    // ---------------------------------------------------------------

    /**
     * nic.it verifies the checksum and answers 2004 / 8027, "invalid reg
     * code", for one that does not add up. The placeholder this used --
     * 01234567890, which appears throughout the fixtures -- does not: its
     * check digit should be 7.
     */
    public function testTheRegistrationCodeChecksumIsRight(): void {
        foreach (['STAAAAAAR1', 'STAAAAAAR2', 'STZZZZZZR1'] as $handle) {
            $code = ContactFactory::registrationCode($handle);

            $this->assertMatchesRegularExpression('/^[0-9]{11}$/', $code);
            $this->assertTrue(self::isValidVat($code), "{$code} would be refused by the registry");
        }
    }

    /**
     * The checker below has to be right for the test above to mean anything,
     * so it is held against real, known-valid VAT numbers -- and against the
     * placeholder that started this.
     */
    public function testTheCheckerAgreesWithRealVatNumbers(): void {
        $this->assertTrue(self::isValidVat('00743110157'));
        $this->assertTrue(self::isValidVat('00488410010'));

        $this->assertFalse(self::isValidVat('01234567890'), 'the old placeholder should not pass');
        $this->assertTrue(self::isValidVat('01234567897'), 'which differs from a valid one by its last digit');
    }

    /**
     * One number identifies one company, so the two registrants in a run must
     * not share theirs.
     */
    public function testTwoContactsGetDifferentCodes(): void {
        $this->assertNotSame(
            ContactFactory::registrationCode('STAAAAAAR1'),
            ContactFactory::registrationCode('STAAAAAAR2')
        );
    }

    public function testTheSameHandleAlwaysGetsTheSameCode(): void {
        $this->assertSame(
            ContactFactory::registrationCode('STAAAAAAR1'),
            ContactFactory::registrationCode('STAAAAAAR1')
        );
    }

    /**
     * Independent of the implementation under test: odd positions counting
     * from one as they stand, even positions doubled with nine taken off
     * anything over nine, and the total a multiple of ten.
     */
    private static function isValidVat(string $vat): bool {
        if ( ! preg_match('/^[0-9]{11}$/', $vat)) {
            return false;
        }
        $sum = 0;

        foreach (str_split($vat) as $i => $digit) {
            $digit = (int) $digit;
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Sending a registration code with entity type 0 is a contradiction the
     * registry would be right to reject.
     */
    public function testANonRegistrantCarriesNoRegistrationCode(): void {
        $contact = $this->filled(false);

        $this->assertSame('', $contact->get('regcode'));
        $this->assertSame('', $contact->get('nationalitycode'));
    }

    public function testEveryContactGetsAnAuthinfo(): void {
        $this->assertNotSame('', $this->filled(true)->get('authinfo'));
        $this->assertNotSame('', $this->filled(false)->get('authinfo'));
    }

    /**
     * example.it is reserved for documentation, so nothing a self-test writes
     * can reach a real mailbox even if the registry mails it.
     */
    public function testTheAddressCannotReachAnybody(): void {
        $this->assertStringEndsWith('@example.it', (string) $this->filled(true)->get('email'));
    }

    /**
     * The update has to touch a postalInfo field, an address field and a plain
     * contact field at once: one that moved only a single kind would not
     * notice a builder that dropped one of the others.
     */
    public function testTheUpdateCoversEachKindOfField(): void {
        $changes = ContactFactory::changes('STAAAAAAD1');

        $this->assertArrayHasKey('name', $changes);   // postalInfo
        $this->assertArrayHasKey('city', $changes);   // addr
        $this->assertArrayHasKey('voice', $changes);  // contact
    }

    public function testTheUpdateActuallyChangesSomething(): void {
        $contact = $this->filled(false);

        foreach (ContactFactory::changes('STAAAAAAR1') as $field => $value) {
            $this->assertNotSame($value, $contact->get($field), "{$field} was already at its updated value");
        }
    }
}
