<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Contact::setEntityType()'s range check used to read `($tmp < 1) && ($tmp > 7)`
 * -- never true, so nothing outside the registry's 1-7 range was reset to the
 * entityType-0 default; it reached the registry, to be refused by the schema.
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
