<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Service\PowerDnsApis;

/**
 * Show and edit `pdns.apis`, the PowerDNS servers `pdns sync` talks to.
 * Same show/add/remove/clear shape as CidrListCommand, but each entry is a
 * server (protocol/host/port/api_key), so writes go through
 * `CronjobSettings::set()` rather than `Config::set()` directly -- the same
 * validation and audit trail every other `config *-set` command shares.
 */
final class ConfigPdnsApiCommand extends Command
{
    public function describe(): string {
        return "show or edit the PowerDNS APIs 'pdns sync' talks to";
    }

    public function arguments(): string {
        return '[add <url> --api-key=<key> | remove <url> | clear]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS + [
            'api-key=' => 'the API key for the server being added (omit to keep an existing one)',
        ];
    }

    public function run(): int {
        $this->database();

        $current = (array) (CronjobSettings::get('pdns')['apis'] ?? []);
        $action = $this->arguments[0] ?? null;

        return match ($action) {
            null     => $this->show($current),
            'clear'  => $this->clear($current),
            'add'    => $this->add($current),
            'remove' => $this->remove($current),
            default  => throw new UsageError("argument must be 'add', 'remove' or 'clear'"),
        };
    }

    /** @param array $current */
    private function show(array $current): int {
        $redacted = PowerDnsApis::redact($current);
        if ($redacted === []) {
            $this->line('no PowerDNS API configured');
            return 0;
        }

        foreach ($redacted as $api) {
            $url = PowerDnsApis::baseUrl($api);
            $keyState = $api['api_key_set'] ? 'key set' : 'key NOT set';
            $this->record("{$url} ({$keyState})", $api + ['url' => $url]);
        }
        return 0;
    }

    /** @param array $current */
    private function clear(array $current): int {
        if (count($this->arguments) > 1) {
            throw new UsageError("'clear' takes no arguments");
        }
        return $this->write($current, []);
    }

    /** @param array $current */
    private function add(array $current): int {
        $url = $this->arguments[1] ?? null;
        if ($url === null) {
            throw new UsageError('give the URL of the PowerDNS API to add, e.g. https://ns1.example.com:8081');
        }

        $entry = $this->parseUrl($url);
        $entry['api_key'] = (string) $this->option('api-key', '');

        $desired = $current;
        $at = $this->indexOf($desired, $entry);
        if ($at !== null) {
            $desired[$at] = $entry;
        } else {
            $desired[] = $entry;
        }

        return $this->write($current, $desired);
    }

    /** @param array $current */
    private function remove(array $current): int {
        $url = $this->arguments[1] ?? null;
        if ($url === null) {
            throw new UsageError('give the URL of the PowerDNS API to remove');
        }

        $entry = $this->parseUrl($url);
        $at = $this->indexOf($current, $entry);
        $desired = $current;
        if ($at !== null) {
            unset($desired[$at]);
            $desired = array_values($desired);
        }

        return $this->write($current, $desired);
    }

    /** @return array{protocol: string, host: string, port: int} */
    private function parseUrl(string $url): array {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new UsageError("'{$url}' is not a valid URL, e.g. https://ns1.example.com:8081");
        }

        $protocol = strtolower($parsed['scheme']);
        if ( ! in_array($protocol, ['http', 'https'], true)) {
            throw new UsageError("{$url}: protocol must be http or https");
        }

        return [
            'protocol' => $protocol,
            'host'     => strtolower(trim($parsed['host'], '[]')),
            'port'     => $parsed['port'] ?? 8081,
        ];
    }

    /** @param array $list @param array{protocol: string, host: string, port: int} $entry */
    private function indexOf(array $list, array $entry): ?int {
        foreach ($list as $i => $api) {
            if ($api['protocol'] === $entry['protocol']
                && $api['host'] === $entry['host']
                && (int) $api['port'] === (int) $entry['port']
            ) {
                return $i;
            }
        }
        return null;
    }

    /** @param array $current @param array $desired */
    private function write(array $current, array $desired): int {
        if ($desired === $current) {
            $this->line('pdns.apis is unchanged');
            return 0;
        }

        $summary = $desired === [] ? '(empty)' : implode(', ', array_map([PowerDnsApis::class, 'baseUrl'], $desired));

        if ( ! $this->confirm("Set pdns.apis to {$summary}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set pdns.apis: {$summary}");
            return 0;
        }

        try {
            $result = CronjobSettings::set('pdns', ['apis' => $desired], $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("pdns.apis set to {$summary}", ['apis' => PowerDnsApis::redact((array) $result['apis'])]);
        return 0;
    }
}
