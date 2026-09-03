<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainDeleteCommand;
use Eppitnic\Cli\Command\DomainStatusCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * The two mechanisms only mutating commands have: --dry-run and the
 * confirmation gate. Both are safety features, so what matters is that they
 * fail closed -- a dry run that quietly sent something would be worse than
 * neither.
 */
final class MutatingCommandTest extends EppTestCase
{
    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    /**
     * A dry run must print the request it would have sent -- and reach no
     * network at all, which is what makes it usable with no credentials.
     */
    public function testDryRunPrintsTheRequestAndSendsNothing(): void {
        $command = new DomainDeleteCommand(['--dry-run', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('<domain:delete', $output);
        $this->assertStringContainsString('<domain:name>example-one.it</domain:name>', $output);
    }

    /**
     * ...and it must not claim to have done the thing.
     */
    public function testDryRunDoesNotReportSuccess(): void {
        $command = new DomainDeleteCommand(['--dry-run', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $command->run());

        $this->assertStringNotContainsString('deleted', $output);
    }

    /**
     * The session plumbing is the same every time and is not what anyone is
     * inspecting, so only the command itself is shown.
     */
    public function testDryRunOmitsTheSessionPlumbing(): void {
        $command = new DomainDeleteCommand(['--dry-run', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $command->run());

        $this->assertStringNotContainsString('<login', $output);
        $this->assertStringNotContainsString('<logout', $output);
        $this->assertStringNotContainsString('<hello', $output);
    }

    /**
     * The generated request has to be well-formed, since the whole point is to
     * hand it to something else to look at.
     */
    public function testDryRunOutputIsWellFormedXml(): void {
        $command = new DomainStatusCommand(['--dry-run', 'add', 'clientHold', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $command->run());

        // the status command fetches before updating, so two documents come out
        foreach (array_filter(preg_split('/(?=<\?xml)/', $output)) as $document) {
            $dom = new \DOMDocument();
            $this->assertTrue(@$dom->loadXML(trim($document)), "not well-formed:\n{$document}");
        }
    }

    /**
     * With no terminal to ask at, the answer is no. A cron job has nobody to
     * confirm, and blocking there would hang the run rather than protect
     * anything.
     */
    public function testConfirmationRefusesWithoutATerminal(): void {
        $command = new DomainDeleteCommand(['example-one.it']);
        $errors = fopen('php://memory', 'w+');
        $command->useErrorStream($errors);

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        rewind($errors);
        $this->assertStringContainsString('Pass --yes', (string) stream_get_contents($errors));
        $this->assertStringContainsString('nothing done', $output);
    }

    public function testStatusRejectsAnUnknownState(): void {
        $this->expectException(UsageError::class);
        (new DomainStatusCommand(['add', 'clientNonsense', 'example-one.it']))->run();
    }

    public function testStatusRejectsAnUnknownAction(): void {
        $this->expectException(UsageError::class);
        (new DomainStatusCommand(['toggle', 'clientHold', 'example-one.it']))->run();
    }

    /**
     * withSession()'s --dry-run client answers from DryRun's canned "1000"
     * responses, never a real session -- Command::withSession() forces
     * keepalive false on it for exactly this reason. Without that, a dry run
     * under a globally-on keepalive would mark a session that was never
     * authenticated as fresh, and the next real command would skip login
     * and walk straight into a 2002.
     */
    public function testDryRunNeverTouchesTheSharedSessionState(): void {
        Config::loadForTesting([
            'keepalive'         => true,
            'session_cookies'   => [],
            'session_timestamp' => 0,
        ] + static::SETTINGS);

        $command = new DomainDeleteCommand(['--dry-run', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $command->run());

        $this->assertSame(0, SessionState::timestamp());
        $this->assertSame([], SessionState::cookies());
    }
}
