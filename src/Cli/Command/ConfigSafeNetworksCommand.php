<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Api\ClientIp;
use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Show and edit `safe_networks`, the ranges whose logins skip the MFA code
 * (Auth reads it through ClientIp::inCidr). A local settings write only:
 * no registry session opens either way.
 */
final class ConfigSafeNetworksCommand extends Command
{
    public function describe(): string {
        return 'show or edit safe_networks, the ranges whose logins skip the MFA code';
    }

    public function arguments(): string {
        return '[add <cidr>... | remove <cidr>... | clear]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $current = array_map('strval', (array) Config::get('safe_networks'));
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
            $this->line('safe_networks is empty -- every login needs its MFA code');
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
        if ($desired === $current) {
            $this->line('safe_networks is unchanged');
            return 0;
        }

        $summary = $desired === [] ? '(empty)' : implode(', ', $desired);

        // Only 'add' loosens anything: it lets every login from that range in
        // without its code. Removing and clearing can lock people out, but
        // that fails closed, so it does not need the same warning.
        if ($action === 'add') {
            $this->warn('Logins from these ranges will skip the MFA code entirely.');
        }

        if ( ! $this->confirm("Set safe_networks to {$summary}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set safe_networks: {$summary}");
            return 0;
        }

        Config::set('safe_networks', $desired);

        $this->record("safe_networks set to {$summary}", ['safe_networks' => $desired]);
        return 0;
    }
}
