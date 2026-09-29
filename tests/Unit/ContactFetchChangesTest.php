<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

final class ContactFetchChangesTest extends EppTestCase
{
    public function testFetchLeavesNothingPendingAndNoopUpdateSendsNothing(): void {
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('ABCD1234EFGH5678'));
        $this->assertSame(1, (int) $contact->get('consentforpublishing'));

        $this->assertFalse($contact->hasChanges());
        $this->assertFalse($contact->update());
        $this->assertStringContainsString('did not change', $contact->getError());
        $this->assertCount(1, $this->transport->requests);
    }
}
