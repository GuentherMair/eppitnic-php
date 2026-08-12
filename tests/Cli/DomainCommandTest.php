<?php

namespace Net\EPP\Tests\Cli;

use Net\EPP\Cli\DomainCheckCommand;
use Net\EPP\Cli\DomainInfoCommand;
use Net\EPP\Tests\Support\CommandCatalog;
use Net\EPP\Tests\Support\EppTestCase;
use Net\EPP\Cli\Command;
use Net\EPP\Tests\Support\FakeTransport;

/**
 * The two read commands, driven end to end against canned registry responses.
 *
 * The transport is substituted through Helpers::withEppSession()'s own Client,
 * which the commands build for themselves -- so what is exercised here is the
 * real path from argv to printed output, not a rearrangement of it.
 */
final class DomainCommandTest extends EppTestCase
{
    /**
     * Point every Client built during $fn at a transport whose queue starts
     * with the greeting and login the session needs.
     *
     * @param string[] $responses what the registry answers, after login
     */
    private function withRegistry(Command $command, array $responses, callable $fn): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);   // hello()
        $transport->queue(CommandCatalog::OK_RESPONSE);         // login()
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::OK_RESPONSE);         // logout()

        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testCheckReportsAvailability(): void {
        $command = new DomainCheckCommand(['example-one.it', 'example-two.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_CHECK_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        $this->assertStringContainsString('example-one.it', $output);
        $this->assertStringContainsString('available', $output);
        $this->assertStringContainsString('example-two.it', $output);
        $this->assertStringContainsString('taken', $output);
    }

    public function testCheckJsonOutputIsParseable(): void {
        $command = new DomainCheckCommand(['--json', 'example-one.it', 'example-two.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_CHECK_RESPONSE], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "not JSON: {$output}");
        $this->assertCount(2, $decoded);
        $this->assertSame('example-one.it', $decoded[0]['domain']);
        $this->assertTrue($decoded[0]['available']);
        $this->assertFalse($decoded[1]['available']);
    }

    public function testInfoPrintsTheRegistryRecord(): void {
        $command = new DomainInfoCommand(['example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_INFO_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        foreach (['example-one.it', 'REGI1234REGI5678', 'ADMIN123ADMIN456', 'ns1.example-one.it', 'expires'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function testInfoJsonCarriesTheStructuredRecord(): void {
        $command = new DomainInfoCommand(['--json', 'example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_INFO_RESPONSE], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('example-one.it', $decoded[0]['domain']);
        $this->assertSame(['TECH1234TECH5678'], $decoded[0]['tech']);
        $this->assertContains('ns1.example-one.it', $decoded[0]['ns']);
    }

    /**
     * A domain the registry does not know must be reported and counted, not
     * silently skipped -- the exit code is what a cron job reads.
     */
    public function testInfoFailsWhenTheRegistryRejectsTheLookup(): void {
        $command = new DomainInfoCommand(['nonexistent.it']);

        // a real captured refusal, not a success response with the data left
        // out: the registry answers a failed domain:info with a 2xxx code, and
        // that is the path worth exercising
        $error = file_get_contents(__DIR__ . '/../fixtures/responses/domain-info-error.xml');

        $this->withRegistry($command, [$error], function () use ($command) {
            $this->assertSame(DOMAIN_FETCH_FAILED, $command->run());
        });
    }

    public function testCheckRejectsAnEmptyNameList(): void {
        $this->expectException(\Net\EPP\Cli\UsageError::class);
        (new DomainCheckCommand([]))->run();
    }

    public function testInfoRejectsAnInvalidContactsOption(): void {
        $this->expectException(\Net\EPP\Cli\UsageError::class);
        (new DomainInfoCommand(['--contacts=nonsense', 'x.it']))->run();
    }
}
