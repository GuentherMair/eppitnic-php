<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ContactCreateCommand;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;

/**
 * `contact create` without an explicit --authinfo.
 *
 * ContactCreateCommand::run() used to read the generated authinfo back via
 * $contact->authinfo -- a direct read of a protected property from outside
 * the class, which PHP refuses with a fatal "Cannot access protected
 * property" rather than the empty-string fallback the code assumed. Nothing
 * caught it: no test in this suite exercised `contact create` end to end, so
 * the crash only ever surfaced live, against a real registry, with the one
 * field everybody expects to be optional.
 */
final class ContactCreateCommandTest extends EppTestCase
{
    private const FIELDS = [
        '--name=Mario Rossi',
        '--street=Via Roma 1',
        '--city=Bolzano',
        '--province=BZ',
        '--postalcode=39100',
        '--countrycode=IT',
        '--voice=+39.0471000000',
        '--email=mario.rossi@example.it',
    ];

    private function withRegistry(callable $fn): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::LOGIN_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);   // the create itself
        $transport->queue(CommandCatalog::LOGIN_RESPONSE); // logout, same shape

        $this->nic->setTransport($transport);

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testCreateWithNoAuthinfoGeneratesOneRatherThanCrashing(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--yes', '--json', 'SELFTEST12345678']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->withRegistry(function () use ($command) {
            $this->assertSame(0, $command->run());
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "output was not valid JSON: {$output}");
        $this->assertSame('SELFTEST12345678', $decoded[0]['handle']);
        $this->assertSame(16, strlen($decoded[0]['authinfo']), 'expected a generated 16-character authinfo');
    }

    public function testCreateStillHonoursAnExplicitAuthinfo(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--authinfo=My-Own-Secret1', '--yes', '--json', 'SELFTEST12345678']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->withRegistry(function () use ($command) {
            $this->assertSame(0, $command->run());
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertSame('My-Own-Secret1', $decoded[0]['authinfo']);
    }
}
