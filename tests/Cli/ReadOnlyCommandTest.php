<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\Command\ContactCheckCommand;
use Eppitnic\Cli\Command\ContactInfoCommand;
use Eppitnic\Cli\Command\DomainExportCommand;
use Eppitnic\Cli\Command\SessionCreditCommand;
use Eppitnic\Cli\Command\SessionHelloCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;

/**
 * The registry-backed read commands, driven from argv to printed output.
 */
final class ReadOnlyCommandTest extends EppTestCase
{
    /**
     * @param string[] $responses what the registry answers after login
     */
    private function withRegistry(Command $command, array $responses, callable $fn): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::LOGIN_RESPONSE);
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::LOGIN_RESPONSE);   // logout, same shape

        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testContactCheckReportsAvailability(): void {
        $command = new ContactCheckCommand(['ABCD1234EFGH5678', 'IJKL9012MNOP3456']);

        $output = $this->withRegistry($command, [CommandCatalog::CONTACT_CHECK_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        $this->assertStringContainsString('ABCD1234EFGH5678', $output);
        $this->assertStringContainsString('available', $output);
        $this->assertStringContainsString('in use', $output);
    }

    public function testContactInfoPrintsThePostalRecord(): void {
        $command = new ContactInfoCommand(['ABCD1234EFGH5678']);

        $output = $this->withRegistry($command, [CommandCatalog::CONTACT_INFO_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        foreach (['Mario Rossi', 'Bolzano', 'mario.rossi@example.it'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function testContactInfoJsonKeepsTheStreetLines(): void {
        $command = new ContactInfoCommand(['--json', 'ABCD1234EFGH5678']);

        $output = $this->withRegistry($command, [CommandCatalog::CONTACT_INFO_RESPONSE], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['Via Roma 1', 'Scala B'], $decoded[0]['street']);
        $this->assertSame('IT', $decoded[0]['countrycode']);
    }

    public function testSessionHelloReportsTheGreeting(): void {
        $command = new SessionHelloCommand([]);

        // hello() does not log in, so only the greeting is queued
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ITNIC EPP Registry', $output);
    }

    public function testSessionCreditReportsTheBalance(): void {
        $command = new SessionCreditCommand([]);

        $output = $this->withRegistry($command, [], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        $this->assertStringContainsString('1234.56', $output);
    }

    public function testExportRejectsAnUnknownSource(): void {
        $this->expectException(UsageError::class);
        (new DomainExportCommand(['--source=elsewhere']))->run();
    }

    public function testRegistryExportNeedsNames(): void {
        $this->expectException(UsageError::class);
        (new DomainExportCommand(['--source=registry']))->run();
    }

    public function testContactCheckRejectsAnEmptyList(): void {
        $this->expectException(UsageError::class);
        (new ContactCheckCommand([]))->run();
    }
}
