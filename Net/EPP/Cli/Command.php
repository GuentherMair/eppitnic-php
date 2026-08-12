<?php

namespace Net\EPP\Cli;

use Net\EPP\Client;
use Net\EPP\Config;
use Net\EPP\Helpers;
use Net\EPP\IT\Session;

/**
 * Base for every `bin/eppitnic` subcommand.
 *
 * Carries the parts every one of the old CLI/ and examples/ scripts wrote out
 * by hand: option parsing, the hello/login/logout dance, reading domain or
 * contact lists from a file or the command line, and the difference between
 * "printed for a human" and "printed for a pipe".
 *
 * A subclass declares what it takes (options(), arguments(), describe()) and
 * implements run(). Anything needing a registry session wraps its work in
 * withSession(), which is Helpers::withEppSession() with the CLI's own error
 * reporting and exit codes around it.
 *
 * @category    Net
 * @package     Net\EPP\Cli
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
abstract class Command
{
    /** parsed long options, e.g. ['file' => 'domains.txt', 'json' => true] */
    protected array $options = [];

    /** positional arguments left after the options */
    protected array $arguments = [];

    /** collected output rows, for --json */
    private array $records = [];

    /** set only by useClient(), for tests */
    private ?Client $client = null;

    /** where warn() writes; swapped by the test suite to keep its output clean */
    private $errorStream = null;

    // ---------------------------------------------------------------
    // what a subcommand declares about itself
    // ---------------------------------------------------------------

    /**
     * @return string one line, shown in the command list and in --help
     */
    abstract public function describe(): string;

    /**
     * @return array<string, string> option name => help text. Append '=' to
     *                               the name for options that take a value.
     */
    public function options(): array {
        return [];
    }

    /**
     * @return string the positional part of the usage line, e.g. '<domain>...'
     */
    public function arguments(): string {
        return '';
    }

    /**
     * @return int an exit code; 0 for success
     */
    abstract public function run(): int;

    // ---------------------------------------------------------------
    // options global to every subcommand
    // ---------------------------------------------------------------

    /**
     * Options every subcommand accepts. --dry-run is deliberately not here:
     * it belongs to the commands that change something, and advertising it
     * globally would promise it on reads, where it does nothing.
     */
    public const GLOBAL_OPTIONS = [
        'verbose' => 'include the full EPP request/response in errors, and record every command to the database',
        'json'    => 'print machine-readable JSON instead of text',
        'user='   => 'act as this local user id (default 1)',
        'help'    => 'show this help',
    ];

    /**
     * @param array $argv arguments after the verb, as given on the command line
     */
    public function __construct(array $argv = []) {
        $this->parse($argv);
    }

    /**
     * Long options only, in `--name` and `--name=value` form.
     *
     * getopt() is deliberately not used: it reads $argv itself, which makes a
     * subcommand impossible to test without faking global state, and it cannot
     * tell an unknown option from a positional argument -- so a typo like
     * `--ns1` was silently ignored by the old scripts rather than reported.
     */
    private function parse(array $argv): void {
        $known = array_merge(self::GLOBAL_OPTIONS, $this->options());
        $valued = [];
        foreach (array_keys($known) as $name) {
            $valued[rtrim($name, '=')] = str_ends_with($name, '=');
        }

        foreach ($argv as $arg) {
            if ( ! str_starts_with($arg, '--')) {
                $this->arguments[] = $arg;
                continue;
            }

            $body = substr($arg, 2);
            $value = true;
            if (str_contains($body, '=')) {
                [$body, $value] = explode('=', $body, 2);
            }

            if ( ! array_key_exists($body, $valued)) {
                throw new UsageError("unknown option '--{$body}'");
            }
            if ($valued[$body] && $value === true) {
                throw new UsageError("option '--{$body}' needs a value, as --{$body}=...");
            }
            if ( ! $valued[$body] && $value !== true) {
                throw new UsageError("option '--{$body}' takes no value");
            }

            $this->options[$body] = $value;
        }
    }

    // ---------------------------------------------------------------
    // reading options
    // ---------------------------------------------------------------

    protected function option(string $name, mixed $default = null): mixed {
        return $this->options[$name] ?? $default;
    }

    protected function hasOption(string $name): bool {
        return array_key_exists($name, $this->options);
    }

    protected function isVerbose(): bool {
        return $this->hasOption('verbose');
    }

    protected function isJson(): bool {
        return $this->hasOption('json');
    }

    protected function userId(): int {
        return (int) $this->option('user', 1);
    }

    /**
     * Names given as positional arguments, or one per line from --file.
     *
     * Both forms were supported by the old scripts through mutually exclusive
     * -d and -f switches; here a file is just another way of supplying the
     * same list, and blank lines and # comments are skipped.
     *
     * @return string[]
     */
    protected function names(): array {
        $names = $this->arguments;

        if ($file = $this->option('file')) {
            if ( ! is_readable($file)) {
                throw new UsageError("'{$file}' is not a readable file");
            }
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $names[] = $line;
                }
            }
        }

        return array_values(array_unique(array_filter($names, fn($n) => trim($n) !== '')));
    }

    // ---------------------------------------------------------------
    // database / registry session
    // ---------------------------------------------------------------

    /**
     * Ensure the database is connected and migrated.
     *
     * Only needed by commands that read or write locally without opening a
     * registry session: everything else gets the connection as a side effect
     * of Client's constructor reading its settings.
     */
    protected function database(): void {
        Config::init();
    }

    /**
     * Run $fn against a logged-in registry session.
     *
     * @param callable $fn function(Client $nic, Session $session)
     * @return mixed whatever $fn returns
     * @throws SessionError if the registry is unreachable or rejects the login
     */
    protected function withSession(callable $fn): mixed {
        try {
            return Helpers::withEppSession($fn, $this->isVerbose(), $this->client);
        } catch (\RuntimeException $e) {
            throw new SessionError($e->getMessage(), 0, $e);
        }
    }

    /**
     * Substitute the client the command talks to. Test suite only -- see the
     * note on Helpers::withEppSession()'s third argument.
     */
    public function useClient(Client $client): void {
        $this->client = $client;
    }

    // ---------------------------------------------------------------
    // output
    // ---------------------------------------------------------------

    /**
     * A line for a human. Suppressed under --json so that stdout stays a
     * single parseable document.
     */
    protected function line(string $text = ''): void {
        if ( ! $this->isJson()) {
            echo $text, "\n";
        }
    }

    /**
     * Something worth saying whatever the output mode -- warnings and errors
     * go to stderr, so redirecting stdout to a file still shows them.
     *
     * Written straight to the stream rather than echoed, precisely so it is
     * not swallowed by an output buffer along with stdout.
     */
    protected function warn(string $text): void {
        fwrite($this->errorStream ?? STDERR, $text . "\n");
    }

    /**
     * Send warnings somewhere other than STDERR. Test suite only.
     *
     * @param resource $stream
     */
    public function useErrorStream($stream): void {
        $this->errorStream = $stream;
    }

    /**
     * One result. Printed immediately in text mode; collected and emitted as a
     * JSON array by flush() under --json.
     *
     * @param string $text how a human should see it
     * @param array $record the same thing as data
     */
    protected function record(string $text, array $record): void {
        if ($this->isJson()) {
            $this->records[] = $record;
        } else {
            echo $text, "\n";
        }
    }

    /**
     * Emit whatever --json collected. Called once by the dispatcher after
     * run() returns, so a subcommand never has to remember to.
     */
    public function flush(): void {
        if ($this->isJson()) {
            echo json_encode($this->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        }
    }

    // ---------------------------------------------------------------
    // help
    // ---------------------------------------------------------------

    public function usage(string $verb): string {
        $text = "Usage: eppitnic {$verb}";
        if ($this->options() !== []) {
            $text .= ' [options]';
        }
        if ($this->arguments() !== '') {
            $text .= ' ' . $this->arguments();
        }
        $text .= "\n\n" . $this->describe() . "\n";

        foreach ([$this->options(), self::GLOBAL_OPTIONS] as $i => $set) {
            if ($set === []) {
                continue;
            }
            $text .= "\n" . ($i === 0 ? "Options:" : "Common options:") . "\n";
            foreach ($set as $name => $help) {
                $flag = '--' . rtrim($name, '=') . (str_ends_with($name, '=') ? '=VALUE' : '');
                $text .= sprintf("  %-22s %s\n", $flag, $help);
            }
        }

        return $text;
    }
}
