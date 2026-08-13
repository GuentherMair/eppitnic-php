<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use PHPUnit\Framework\TestCase;

/**
 * Option parsing and dispatch, which is where a CLI quietly does the wrong
 * thing: the scripts this replaces used getopt(), which cannot distinguish an
 * unknown switch from a positional argument, so a mistyped option was ignored
 * and the command ran anyway with the wrong inputs.
 */
final class CommandParsingTest extends TestCase
{
    private function command(array $argv): Command {
        return new class($argv) extends Command {
            public function describe(): string { return 'a test command'; }
            public function arguments(): string { return '<name>...'; }
            public function options(): array {
                return ['file=' => 'a file', 'store' => 'a flag'];
            }
            public function run(): int { return 0; }

            public function exposedNames(): array { return $this->names(); }
            public function exposedOption(string $n, mixed $d = null): mixed { return $this->option($n, $d); }
            public function exposedUserId(): int { return $this->userId(); }
            public function exposedVerbose(): bool { return $this->isVerbose(); }
        };
    }

    public function testPositionalArgumentsAreCollected(): void {
        $this->assertSame(['one.it', 'two.it'], $this->command(['one.it', 'two.it'])->exposedNames());
    }

    public function testValuedAndFlagOptions(): void {
        $c = $this->command(['--store', '--user=7', 'x.it']);

        $this->assertTrue($c->exposedOption('store'));
        $this->assertSame(7, $c->exposedUserId());
        $this->assertSame(['x.it'], $c->exposedNames());
    }

    public function testUserIdDefaultsToOne(): void {
        $this->assertSame(1, $this->command([])->exposedUserId());
    }

    public function testUnknownOptionIsRejected(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage("unknown option '--nope'");
        $this->command(['--nope']);
    }

    public function testValuedOptionWithoutValueIsRejected(): void {
        $this->expectException(UsageError::class);
        $this->command(['--file']);
    }

    public function testFlagOptionWithValueIsRejected(): void {
        $this->expectException(UsageError::class);
        $this->command(['--store=yes']);
    }

    /**
     * A value containing '=' must survive: authinfo codes and passwords do
     * contain them.
     */
    public function testValueMayContainEqualsSign(): void {
        $this->assertSame('a=b=c', $this->command(['--file=a=b=c'])->exposedOption('file'));
    }

    public function testNamesComeFromFileAndArguments(): void {
        $file = tempnam(sys_get_temp_dir(), 'eppitnic-names-');
        file_put_contents($file, "# a comment\nfrom-file.it\n\n  spaced.it  \n");

        $names = $this->command(['arg.it', "--file={$file}"])->exposedNames();
        unlink($file);

        $this->assertSame(['arg.it', 'from-file.it', 'spaced.it'], $names);
    }

    public function testDuplicateNamesAreCollapsed(): void {
        $this->assertSame(['a.it', 'b.it'], $this->command(['a.it', 'b.it', 'a.it'])->exposedNames());
    }

    public function testUnreadableFileIsAUsageError(): void {
        $this->expectException(UsageError::class);
        $this->command(['--file=/nonexistent/nowhere.txt'])->exposedNames();
    }

    // ---------------------------------------------------------------
    // dispatch
    // ---------------------------------------------------------------

    /**
     * Every registered verb must resolve to a usable command, so a typo in the
     * registry is caught here rather than by a user.
     */
    public function testEveryRegisteredCommandIsConstructible(): void {
        foreach (Application::commands() as $verb => $class) {
            $this->assertTrue(class_exists($class), "{$verb} maps to a missing class");

            $command = new $class();
            $this->assertNotSame('', $command->describe(), "{$verb} has no description");
            $this->assertStringContainsString("eppitnic {$verb}", $command->usage($verb));
        }
    }

    /**
     * Multi-word verbs must match longest-first, or 'domain transfer approve'
     * would be swallowed by 'domain transfer' once both exist.
     */
    public function testLongestVerbWins(): void {
        $application = new Application();

        $reflection = new \ReflectionMethod(Application::class, 'match');
        [$verb, $rest] = $reflection->invoke($application, ['domain', 'info', 'example.it']);

        $this->assertSame('domain info', $verb);
        $this->assertSame(['example.it'], $rest);
    }

    public function testUnknownVerbIsNotMatched(): void {
        $reflection = new \ReflectionMethod(Application::class, 'match');
        [$verb] = $reflection->invoke(new Application(), ['domain', 'frobnicate']);

        $this->assertNull($verb);
    }

    public function testOverviewListsEveryCommand(): void {
        $overview = (new Application())->overview();

        foreach (array_keys(Application::commands()) as $verb) {
            $this->assertStringContainsString($verb, $overview);
        }
    }
}
