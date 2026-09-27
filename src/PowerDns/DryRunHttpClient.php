<?php

namespace Eppitnic\PowerDns;

/**
 * Answers every request itself, so `pdns sync --dry-run` runs the real
 * `Api` logic with nothing reaching a server -- the same role
 * `Epp\Transport\DryRun` plays for EPP. A zone this run has already
 * "created" reads back as existing, so a create followed by an update in
 * the same dry run does not print a second create-zone.
 */
final class DryRunHttpClient implements HttpClient
{
    /** @var array<string, true> zone id => true, this run's POSTs */
    private array $created = [];

    /** @var array<int, array{method: string, url: string, body: ?string}> */
    private array $sent = [];

    public function request(string $method, string $url, array $headers, ?string $body): array {
        $this->sent[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if ($method === 'GET') {
            $zone = rawurldecode((string) substr($url, strrpos($url, '/') + 1));
            return ['status' => isset($this->created[$zone]) ? 200 : 404, 'body' => '', 'error' => ''];
        }
        if ($method === 'POST') {
            $decoded = json_decode((string) $body, true);
            $this->created[(string) ($decoded['name'] ?? '')] = true;
            return ['status' => 201, 'body' => '', 'error' => ''];
        }
        // PATCH/DELETE
        return ['status' => 204, 'body' => '', 'error' => ''];
    }

    /** @return array<int, array{method: string, url: string, body: ?string}> in order */
    public function sentRequests(): array {
        return $this->sent;
    }
}
