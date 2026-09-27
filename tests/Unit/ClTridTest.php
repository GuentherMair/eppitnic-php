<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Tests\Support\EppTestCase;

/**
 * The longest prefix Validate accepts must survive whole: set_clTRID() cuts
 * the clTRID to epp:trIDStringType's 64, the width of the cl_trid columns.
 */
final class ClTridTest extends EppTestCase
{
    private const PREFIX = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    protected const SETTINGS = [
        'epp' => ['cl_trid_prefix' => self::PREFIX] + parent::SETTINGS['epp'],
    ] + parent::SETTINGS;

    public function testALongestPrefixFitsTheClTridUncut(): void {
        $clTRID = $this->nic->set_clTRID();

        $this->assertStringStartsWith(self::PREFIX . '-', $clTRID);
        $this->assertLessThanOrEqual(64, strlen($clTRID));
    }
}
