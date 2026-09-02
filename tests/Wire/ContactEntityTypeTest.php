<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Contact::setEntityType()'s range check used to read
 * `if (($tmp < 1) && ($tmp > 7))` -- a value can never be both less than 1
 * and greater than 7 at once, so the condition was always false and nothing
 * out of the registry's 1-7 range was ever reset to the entityType-0
 * ("not a registrant") default. It would have reached the registry as-is,
 * to be refused by the extcon-1.0.xsd's own entityTypeType bounds instead.
 */
final class ContactEntityTypeTest extends EppTestCase
{
    public function testEveryValueOneThroughSevenIsAccepted(): void {
        for ($type = 1; $type <= 7; $type++) {
            $contact = new Contact($this->nic);
            $contact->set('entitytype', (string) $type);
            $this->assertSame($type, $contact->get('entitytype'), "entitytype {$type} was not accepted as given");
        }
    }

    public function testAValueAboveSevenFallsBackToZero(): void {
        $contact = new Contact($this->nic);
        $contact->set('entitytype', '8');
        $this->assertSame(0, $contact->get('entitytype'));
    }

    public function testANegativeValueFallsBackToZero(): void {
        $contact = new Contact($this->nic);
        $contact->set('entitytype', '-1');
        $this->assertSame(0, $contact->get('entitytype'));
    }
}
