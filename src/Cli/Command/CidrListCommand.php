<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Api\ClientIp;
use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Show and edit one setting that is a list of networks (`safe_networks`,
 * `trusted_proxies`). A local settings write only: no registry session
 * opens either way.
 */
abstract class CidrListCommand extends Command
{
    /** the settings key being edited */
    abstract protected function key(): string;

    /** what an empty list means, shown instead of an empty listing */
    abstract protected function emptyMessage(): string;

    /** shown before adding, since adding is what loosens anything */
    abstract protected function addWarning(): string;

    /**
     * @param string[] $desired
     * @throws UsageError if the list must not be written
     */
    protected function validate(array $desired): void {
    }

    /** @param string[] $desired */
    protected function persist(array $desired): void {
        Config::set($this->key(), $desired);
    }

    public function arguments(): string {
        return '[add <cidr>... | remove <cidr>... | clear]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $current = array_map('strval', (array) Config::get($this->key()));
        $action = $this->arguments[0] ?? null;
        $rest = array_slice($this->arguments, 1);

        if ($action === null) {
            return $this->show($current);
        }

        if ($action === 'clear') {
            if ($rest !== []) {
                throw new UsageError("'clear' takes no arguments");
            }
            return $this->write($current, [], $action);
        }

        return match ($action) {
            'add'    => $this->write($current, $this->added($current, $rest), $action),
            'remove' => $this->write($current, $this->removed($current, $rest), $action),
            default  => throw new UsageError("argument must be 'add', 'remove' or 'clear'"),
        };
    }

    /**
     * @param string[] $current
     */
    private function show(array $current): int {
        if ($current === []) {
            $this->line($this->emptyMessage());
            return 0;
        }

        foreach ($current as $cidr) {
            $this->record($cidr, ['cidr' => $cidr]);
        }
        return 0;
    }

    /**
     * @param string[] $current
     * @param string[] $given
     * @return string[]
     */
    private function added(array $current, array $given): array {
        $result = self::canonical($current);

        foreach ($this->cidrs($given) as $cidr) {
            if (in_array($cidr, $result, true)) {
                $this->line("{$cidr} is already listed");
                continue;
            }
            $result[] = $cidr;
        }
        return $result;
    }

    /**
     * @param string[] $current
     * @param string[] $given
     * @return string[]
     */
    private function removed(array $current, array $given): array {
        $result = self::canonical($current);

        foreach ($this->cidrs($given) as $cidr) {
            $at = array_search($cidr, $result, true);
            if ($at === false) {
                $this->line("{$cidr} is not listed");
                continue;
            }
            unset($result[$at]);
        }
        return array_values($result);
    }

    /**
     * The arguments as canonical CIDRs.
     *
     * @param string[] $given
     * @return string[]
     * @throws UsageError if none were given, or one is not a network
     */
    private function cidrs(array $given): array {
        if ($given === []) {
            throw new UsageError('give at least one network, e.g. 10.0.0.0/8 or 2001:db8::/32');
        }

        $cidrs = [];
        foreach ($given as $value) {
            $cidr = ClientIp::canonicalCidr($value);
            if ($cidr === null) {
                throw new UsageError("'{$value}' is not a network -- give an address, optionally with a /prefix");
            }
            $cidrs[] = $cidr;
        }
        return $cidrs;
    }

    /**
     * Entries already stored, in the form a comparison can use. An entry that
     * does not parse is kept as-is rather than dropped: this command edits one
     * list member, it does not get to discard what it fails to understand.
     *
     * @param string[] $current
     * @return string[]
     */
    private static function canonical(array $current): array {
        return array_map(fn(string $cidr) => ClientIp::canonicalCidr($cidr) ?? $cidr, $current);
    }

    /**
     * @param string[] $current as stored
     * @param string[] $desired what to write instead
     */
    private function write(array $current, array $desired, string $action): int {
        $key = $this->key();

        if ($desired === $current) {
            $this->line("{$key} is unchanged");
            return 0;
        }

        $this->validate($desired);
        $summary = $desired === [] ? '(empty)' : implode(', ', $desired);

        // Removing and clearing fail closed, so only adding gets the warning
        if ($action === 'add') {
            $this->warn($this->addWarning());
        }

        if ( ! $this->confirm("Set {$key} to {$summary}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set {$key}: {$summary}");
            return 0;
        }

        $this->persist($desired);

        $this->record("{$key} set to {$summary}", [$key => $desired]);
        return 0;
    }
}
