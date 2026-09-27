<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\PowerDns\HttpClient;

/**
 * Records every call and answers like a real (idempotent) PowerDNS server
 * would, with no network involved: a zone POSTed earlier reads back as
 * existing, an unknown one is 404, a deleted one goes back to 404. A host
 * can be made to fail every call (`failHost()`) or everything can
 * (`failAll()`), for the multi-API partial-failure and single-API-failure
 * cases.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int, array{method: string, url: string, body: ?string}> */
    private array $log = [];

    /** @var array<string, true> */
    private array $existingZones;

    /** @var array<string, array{int, string}> URL substring => [status, body] */
    private array $failingHosts = [];

    private bool $failAll = false;
    private int $failStatus = 500;
    private string $failBody = '{"error":"stub failure"}';

    /** @param string[] $existingZones zone ids (with trailing dot) that already exist */
    public function __construct(array $existingZones = []) {
        $this->existingZones = array_fill_keys($existingZones, true);
    }

    public function failAll(int $status = 500, string $body = '{"error":"stub failure"}'): void {
        $this->failAll = true;
        $this->failStatus = $status;
        $this->failBody = $body;
    }

    /** every call whose URL contains $urlFragment (e.g. a host:port) fails */
    public function failHost(string $urlFragment, int $status = 500, string $body = '{"error":"stub failure"}'): void {
        $this->failingHosts[$urlFragment] = [$status, $body];
    }

    /** @return array<int, array{method: string, url: string, body: ?string}> in order */
    public function calls(): array {
        return $this->log;
    }

    public function request(string $method, string $url, array $headers, ?string $body): array {
        $this->log[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if ($this->failAll) {
            return ['status' => $this->failStatus, 'body' => $this->failBody, 'error' => ''];
        }
        foreach ($this->failingHosts as $fragment => [$status, $failBody]) {
            if (str_contains($url, $fragment)) {
                return ['status' => $status, 'body' => $failBody, 'error' => ''];
            }
        }

        $zone = rawurldecode((string) substr($url, strrpos($url, '/') + 1));

        return match ($method) {
            'GET'    => ['status' => isset($this->existingZones[$zone]) ? 200 : 404, 'body' => '', 'error' => ''],
            'POST'   => $this->handleCreate($body),
            'DELETE' => $this->handleDelete($zone),
            default  => ['status' => 204, 'body' => '', 'error' => ''], // PATCH
        };
    }

    /** @return array{status: int, body: string, error: string} */
    private function handleCreate(?string $body): array {
        $decoded = json_decode((string) $body, true);
        $zone = (string) ($decoded['name'] ?? '');
        if (isset($this->existingZones[$zone])) {
            return ['status' => 409, 'body' => '{"error":"Zone already exists"}', 'error' => ''];
        }
        $this->existingZones[$zone] = true;
        return ['status' => 201, 'body' => '', 'error' => ''];
    }

    /** @return array{status: int, body: string, error: string} */
    private function handleDelete(string $zone): array {
        if ( ! isset($this->existingZones[$zone])) {
            return ['status' => 404, 'body' => '{"error":"Not Found"}', 'error' => ''];
        }
        unset($this->existingZones[$zone]);
        return ['status' => 204, 'body' => '', 'error' => ''];
    }
}
