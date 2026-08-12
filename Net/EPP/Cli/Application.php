<?php

namespace Net\EPP\Cli;

/**
 * Maps a verb to the class that implements it, and runs it.
 *
 * Kept separate from bin/eppitnic so the dispatch can be exercised by the test
 * suite without spawning a process: bin/eppitnic is then only argv, exit codes
 * and STDERR.
 */
final class Application
{
    /**
     * verb => command class.
     *
     * Two-word verbs are the norm ('domain info'); the dispatcher matches the
     * longest one first, so 'domain transfer approve' wins over
     * 'domain transfer' when both are registered.
     *
     * @return array<string, class-string<Command>>
     */
    public static function commands(): array {
        return [
            'domain info'             => DomainInfoCommand::class,
            'domain check'            => DomainCheckCommand::class,
            'domain export'           => DomainExportCommand::class,
            'domain create'           => DomainCreateCommand::class,
            'domain import'           => DomainImportCommand::class,
            'domain transfer'         => DomainTransferCommand::class,
            'domain set-owner'        => DomainSetOwnerCommand::class,
            'domain delete'           => DomainDeleteCommand::class,
            'domain restore'          => DomainRestoreCommand::class,
            'domain status'           => DomainStatusCommand::class,
            'contact info'            => ContactInfoCommand::class,
            'contact check'           => ContactCheckCommand::class,
            'session hello'           => SessionHelloCommand::class,
            'session credit'          => SessionCreditCommand::class,
            'poll list'               => PollListCommand::class,
            'doctor ownership'        => DoctorOwnershipCommand::class,
            'doctor inactive-domains' => DoctorInactiveDomainsCommand::class,
        ];
    }

    /**
     * @param string[] $argv the full argv, including the script name at [0]
     * @return int exit code
     */
    public function run(array $argv): int {
        $args = array_slice($argv, 1);

        if ($args === [] || $args[0] === '--help' || $args[0] === 'help') {
            fwrite(STDOUT, $this->overview());
            return 0;
        }

        [$verb, $rest] = $this->match($args);
        if ($verb === null) {
            fwrite(STDERR, "Unknown command: " . implode(' ', $args) . "\n\n");
            fwrite(STDERR, $this->overview());
            return SYNTAX_ERROR;
        }

        $class = self::commands()[$verb];

        try {
            $command = new $class($rest);
        } catch (UsageError $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n\n");
            // built with no arguments purely to render its usage
            fwrite(STDERR, (new $class())->usage($verb));
            return SYNTAX_ERROR;
        }

        if (in_array('--help', $rest, true)) {
            fwrite(STDOUT, $command->usage($verb));
            return 0;
        }

        try {
            $code = $command->run();
            $command->flush();
            return $code;
        } catch (UsageError $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n\n");
            fwrite(STDERR, $command->usage($verb));
            return SYNTAX_ERROR;
        } catch (SessionError $e) {
            fwrite(STDERR, "Registry session unavailable: " . $e->getMessage() . "\n");
            return LOGIN_FAILED;
        }
    }

    /**
     * Longest-prefix match of the leading words against a known verb.
     *
     * @param string[] $args
     * @return array{0: ?string, 1: string[]} the verb and what follows it
     */
    private function match(array $args): array {
        $commands = self::commands();

        for ($length = min(3, count($args)); $length >= 1; $length--) {
            $candidate = implode(' ', array_slice($args, 0, $length));
            if (isset($commands[$candidate])) {
                return [$candidate, array_slice($args, $length)];
            }
        }
        return [null, []];
    }

    /**
     * @return string the command list, grouped by first word
     */
    public function overview(): string {
        $text = "Usage: eppitnic <command> [options] [arguments]\n\n";

        $groups = [];
        foreach (self::commands() as $verb => $class) {
            $groups[explode(' ', $verb)[0]][$verb] = (new $class())->describe();
        }

        foreach ($groups as $group => $verbs) {
            $text .= "{$group}\n";
            foreach ($verbs as $verb => $description) {
                $text .= sprintf("  %-30s %s\n", $verb, $description);
            }
            $text .= "\n";
        }

        $text .= "Run 'eppitnic <command> --help' for the options of one command.\n";
        return $text;
    }
}
