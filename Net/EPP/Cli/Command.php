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

    /** set while a --dry-run session is in flight */
    private ?DryRunTransport $dryRun = null;

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
     * Options every subcommand accepts.
     *
     * --dry-run and --yes are not here: they belong to the commands that
     * change something. Advertising them globally would promise behaviour that
     * reads do not have. A mutating command declares MUTATING_OPTIONS.
     */
    public const GLOBAL_OPTIONS = [
        'verbose' => 'include the full EPP request/response in errors, and record every command to the database',
        'json'    => 'print one JSON document describing the result',
        'jsonl'   => 'print one JSON object per line (JSON Lines), for bulk output',
        'user='   => 'act as this local user id (default 1)',
        'help'    => 'show this help',
    ];

    /**
     * What a command that changes something adds to its own options().
     */
    public const MUTATING_OPTIONS = [
        'dry-run' => 'print the EPP request that would be sent, and send nothing',
        'yes'     => 'do not ask for confirmation',
    ];

    public const FORMAT_TEXT  = 'text';
    public const FORMAT_JSON  = 'json';
    public const FORMAT_JSONL = 'jsonl';
    public const FORMAT_CSV   = 'csv';

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

    /**
     * How results should be printed.
     *
     * --json is one document, which suits a command answering about one thing;
     * --jsonl is one object per line, which stays greppable and streamable for
     * a dump of thousands. A command may default to something else -- see
     * DomainExportCommand, which defaults to CSV.
     */
    protected function format(): string {
        if ($this->hasOption('json') && $this->hasOption('jsonl')) {
            throw new UsageError('--json and --jsonl are alternatives; give one or neither');
        }
        if ($this->hasOption('json')) {
            return self::FORMAT_JSON;
        }
        if ($this->hasOption('jsonl')) {
            return self::FORMAT_JSONL;
        }
        return $this->defaultFormat();
    }

    /**
     * @return string what this command prints when no format is asked for
     */
    protected function defaultFormat(): string {
        return self::FORMAT_TEXT;
    }

    /**
     * Whether output is for a program rather than a person -- in which case
     * the running commentary is suppressed so stdout stays parseable.
     */
    protected function isMachineReadable(): bool {
        return $this->format() !== self::FORMAT_TEXT;
    }

    protected function isJson(): bool {
        return $this->format() === self::FORMAT_JSON;
    }

    protected function userId(): int {
        return (int) $this->option('user', 1);
    }

    protected function isDryRun(): bool {
        return $this->hasOption('dry-run');
    }

    /**
     * Ask before doing something that cannot be undone.
     *
     * Answers yes without asking when --yes was given, when the command is
     * only printing what it would do, or when stdin is not a terminal -- a
     * cron job has nobody to answer, and blocking there would hang the run
     * rather than protect anything.
     *
     * @param string $question stated so that the consequence is visible
     * @return bool whether to proceed
     */
    protected function confirm(string $question): bool {
        if ($this->hasOption('yes') || $this->isDryRun()) {
            return true;
        }
        if ( ! stream_isatty(STDIN)) {
            $this->warn($question);
            $this->warn('Not confirmed: no terminal to ask at. Pass --yes to proceed.');
            return false;
        }

        fwrite(STDOUT, $question . ' [y/N] ');
        $answer = trim((string) fgets(STDIN));
        return in_array(strtolower($answer), ['y', 'yes'], true);
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
     * @param Client|null $override talk to this client instead of a default
     *                    one -- for the restore endpoint, which is a different
     *                    host. Ignored under --dry-run, which answers locally.
     * @return mixed whatever $fn returns
     * @throws SessionError if the registry is unreachable or rejects the login
     */
    protected function withSession(callable $fn, ?Client $override = null): mixed {
        $client = $override ?? $this->client;

        if ($this->isDryRun()) {
            // a client of its own, answering locally: nothing reaches the
            // network, so this needs neither credentials nor connectivity
            $client = new Client();
            $client->setTransport($this->dryRun = new DryRunTransport());
        }

        try {
            $result = Helpers::withEppSession($fn, $this->isVerbose(), $client);
        } catch (\RuntimeException $e) {
            throw new SessionError($e->getMessage(), 0, $e);
        }

        if ($this->dryRun !== null) {
            $this->reportDryRun();
        }

        return $result;
    }

    /**
     * Print the requests a dry run would have sent.
     */
    private function reportDryRun(): void {
        $requests = $this->dryRun->sentRequests();

        if ($requests === []) {
            $this->warn('dry run: nothing would have been sent');
            return;
        }

        foreach ($requests as $request) {
            // straight to stdout: the XML is the point of the exercise, and it
            // is what someone will pipe into a validator
            echo rtrim($request), "\n";
        }
        $this->warn('dry run: ' . count($requests) . ' request(s) shown, none sent');
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
        if ( ! $this->isMachineReadable()) {
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
        // a dry run has not done anything, so it must not report having done
        // it -- the requests printed at the end are its entire output
        if ($this->isDryRun()) {
            return;
        }

        switch ($this->format()) {
            case self::FORMAT_JSON:
                // held until flush(): one document means one array
                $this->records[] = $record;
                break;
            case self::FORMAT_JSONL:
                // emitted as it happens, so a long run can be piped and read
                // before it finishes
                echo json_encode($record, JSON_UNESCAPED_SLASHES), "\n";
                break;
            default:
                echo $text, "\n";
        }
    }

    /**
     * Emit whatever --json collected. Called once by the dispatcher after
     * run() returns, so a subcommand never has to remember to.
     */
    public function flush(): void {
        if ($this->format() === self::FORMAT_JSON) {
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
