<?php

namespace Eppitnic\Selftest;

use Eppitnic\Epp\Contact;

/**
 * The contacts a self-test run creates.
 *
 * Their details are fabricated but have to be *plausible* -- nic.it validates
 * the extcon registrant block, so a registrant needs a nationality, an entity
 * type and a registration code, while an admin or tech contact is entity type
 * 0 and must not carry them at all. The values match the ones the cookbook
 * documents, so a self-test failure points at the library rather than at an
 * invented address the registry happened to dislike.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\ContactFactory
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactFactory
{
    /** an Italian company: the entity type that needs a registration code */
    public const ENTITY_COMPANY = 2;

    /** an admin or tech contact, which carries no registrant details */
    public const ENTITY_NONE = 0;

    /**
     * The registrant entity types that may keep their details out of the
     * public whois: 1, an Italian or foreign natural person, and 3, a
     * freelancer.
     *
     * Anybody else is published by law, and the registry enforces it -- a
     * company registrant sent `consentForPublishing` = 0 is refused with 2308
     * and extended reason 8028, "consentForPublishing cannot be set to false
     * if entity type != 1 and entity type != 3".
     */
    public const MAY_WITHHOLD_CONSENT = [1, 3];

    /**
     * How many digits a partita IVA has, the last of which is a check digit.
     */
    private const VAT_LENGTH = 11;

    /**
     * Fill in a contact, ready to be created.
     *
     * @param Contact $contact a fresh contact
     * @param string $handle the handle this run gave it
     * @param bool $isRegistrant whether it needs the extcon registrant block
     */
    public static function fill(Contact $contact, string $handle, bool $isRegistrant): void {
        $entityType = $isRegistrant ? self::ENTITY_COMPANY : self::ENTITY_NONE;

        $contact->set('handle', $handle);

        $contact->set('name', 'Selftest ' . $handle);
        $contact->set('org', 'Eppitnic Selftest');
        $contact->set('street', 'Via Roma 1');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39100');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.0471000000');
        // example.it is reserved for documentation, so nothing a self-test
        // writes can reach a real mailbox even if the registry mails it
        $contact->set('email', strtolower($handle) . '@example.it');

        if ($entityType !== self::ENTITY_NONE) {
            $contact->set('nationalitycode', 'IT');
            $contact->set('entitytype', $entityType);
            $contact->set('regcode', self::registrationCode($handle));
        }

        // A test contact has no business in the public whois -- but only the
        // entity types that are allowed to withhold consent may say so, and
        // the registrant here is a company.
        if (self::mayWithholdConsent($entityType)) {
            $contact->unsetConsent();
        } else {
            $contact->setConsent();
        }
    }

    /**
     * A registration code entity type 2 will be given: a partita IVA whose
     * check digit is right.
     *
     * It has to be computed rather than picked. nic.it verifies the checksum
     * and answers 2004 with extended reason 8027, "invalid reg code", for one
     * that does not add up -- which the obvious placeholder `01234567890` does
     * not: its check digit should be 7.
     *
     * Derived from the handle, so the two registrants in a run get different
     * codes -- one number identifies one company -- and so that re-running
     * against the same handle asks for the same thing twice.
     *
     * @param string $handle the contact this code is for
     * @return string eleven digits
     */
    public static function registrationCode(string $handle): string {
        // no range of VAT numbers is reserved for testing, so this may well
        // collide with a real company's. Harmless on the test registry, where
        // the rest of the contact is plainly invented anyway.
        $body = substr(str_pad((string) crc32($handle), self::VAT_LENGTH - 1, '0', STR_PAD_LEFT), -(self::VAT_LENGTH - 1));

        return $body . self::vatCheckDigit($body);
    }

    /**
     * The Luhn-style check digit that closes a partita IVA.
     *
     * Odd positions counting from one are added as they stand; even positions
     * are doubled, and nine subtracted from anything that then exceeds nine.
     * The check digit is whatever brings the total to a multiple of ten.
     *
     * @param string $body the first ten digits
     */
    private static function vatCheckDigit(string $body): int {
        $sum = 0;

        for ($i = 0; $i < self::VAT_LENGTH - 1; $i++) {
            $digit = (int) $body[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * Whether this entity type is allowed to keep its details unpublished.
     *
     * Entity type 0 is not a registrant at all -- it carries no extcon
     * registrant block for the rule to apply to.
     */
    public static function mayWithholdConsent(int $entityType): bool {
        return $entityType === self::ENTITY_NONE
            || in_array($entityType, self::MAY_WITHHOLD_CONSENT, true);
    }

    /**
     * The values a self-test changes on an update, and expects to read back.
     *
     * Deliberately every kind of field the contact update carries at once: a
     * postalInfo field, an address field and a plain contact field. An update
     * that only moved one of the three would not notice a builder that dropped
     * one of the others.
     *
     * @return array<string, string> field => new value
     */
    public static function changes(string $handle): array {
        return [
            'name'  => 'Selftest ' . $handle . ' (updated)',
            'city'  => 'Merano',
            'voice' => '+39.0473000000',
        ];
    }
}
