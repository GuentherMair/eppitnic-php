<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Service\PollProcessor;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * verifyTransfer() tells a transfer-out from a transfer-in by comparing acID
 * with who we are. An EPP user logs in under another name than the registrar
 * tag the registry puts into acID, so the tag decides, not the login.
 */
final class PollProcessorRegistrarTagTest extends EppTestCase
{
    private function registrarTag(array $epp): string {
        Config::loadForTesting(['epp' => $epp + static::SETTINGS['epp']] + static::SETTINGS);
        $client = new Client();
        $processor = new PollProcessor($client);
        return (new \ReflectionMethod($processor, 'registrarTag'))->invoke($processor);
    }

    public function testTheTagWinsOverAnEppUserLogin(): void {
        $this->assertSame('MYCOMPANY-REG', $this->registrarTag(['registrar_tag' => 'MYCOMPANY-REG', 'username' => 'mario.rossi']));
    }

    public function testTheLoginStandsInWhenNoTagIsSet(): void {
        $this->assertSame('TEST-REG', $this->registrarTag(['registrar_tag' => '']));
    }

    public function testTheLoginStandsInWhenTheTagPredatesTheSetting(): void {
        $this->assertSame('TEST-REG', $this->registrarTag([]));
    }
}
