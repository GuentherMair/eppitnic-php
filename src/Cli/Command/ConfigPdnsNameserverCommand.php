<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Service\PowerDnsNameservers;

/**
 * Show and edit `pdns.nameservers`, the hostnames `pdns sync` queues a
 * domain for when it touches one of them -- an empty list means nothing
 * ever syncs. Same show/add/remove/clear shape as `config pdns-api`;
 * writes go through `CronjobSettings::set()` for shared validation/audit.
 */
final class ConfigPdnsNameserverCommand extends Command
{
    public function describe(): string {
        return "show or edit pdns.nameservers, the hosts 'pdns sync' watches for";
    }

    public function arguments(): string {
        return '[add <host>... | remove <host>... | clear]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $current = (array) (CronjobSettings::get('pdns')['nameservers'] ?? []);
        $action = $this->arguments[0] ?? null;
        $hosts = array_slice($this->arguments, 1);

        return match ($action) {
            null     => $this->show($current),
            'clear'  => $this->clear($current, $hosts),
            'add'    => $this->add($current, $hosts),
            'remove' => $this->remove($current, $hosts),
            default  => throw new UsageError("argument must be 'add', 'remove' or 'clear'"),
        };
    }

    /** @param string[] $current */
    private function show(array $current): int {
        if ($current === []) {
            $this->line('no nameserver configured -- pdns sync will queue nothing');
            return 0;
        }

        foreach ($current as $host) {
            $this->record($host, ['host' => $host]);
        }
        return 0;
    }

    /** @param string[] $current @param string[] $extra */
    private function clear(array $current, array $extra): int {
        if ($extra !== []) {
            throw new UsageError("'clear' takes no arguments");
        }
        return $this->write($current, []);
    }

    /** @param string[] $current @param string[] $hosts */
    private function add(array $current, array $hosts): int {
        if ($hosts === []) {
            throw new UsageError('give at least one nameserver hostname to add');
        }

        $desired = $current;
        foreach ($hosts as $host) {
            $normalized = PowerDnsNameservers::normalize($host);
            if ( ! in_array($normalized, $desired, true)) {
                $desired[] = $normalized;
            }
        }
        return $this->write($current, $desired);
    }

    /** @param string[] $current @param string[] $hosts */
    private function remove(array $current, array $hosts): int {
        if ($hosts === []) {
            throw new UsageError('give at least one nameserver hostname to remove');
        }

        $desired = $current;
        foreach ($hosts as $host) {
            $at = array_search(PowerDnsNameservers::normalize($host), $desired, true);
            if ($at !== false) {
                unset($desired[$at]);
            }
        }
        return $this->write($current, array_values($desired));
    }

    /** @param string[] $current @param string[] $desired */
    private function write(array $current, array $desired): int {
        if ($desired === $current) {
            $this->line('pdns.nameservers is unchanged');
            return 0;
        }

        $summary = $desired === [] ? '(empty)' : implode(', ', $desired);

        if ( ! $this->confirm("Set pdns.nameservers to {$summary}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set pdns.nameservers: {$summary}");
            return 0;
        }

        try {
            CronjobSettings::set('pdns', ['nameservers' => $desired], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("pdns.nameservers set to {$summary}", ['nameservers' => $desired]);
        return 0;
    }
}
