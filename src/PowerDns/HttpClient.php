<?php

namespace Eppitnic\PowerDns;

/**
 * One HTTP call, abstracted so `Api` is testable without a real PowerDNS
 * server and without curl. `status = 0` means the request never reached a
 * server (a transport error, described in `error`).
 */
interface HttpClient
{
    /** @return array{status: int, body: string, error: string} */
    public function request(string $method, string $url, array $headers, ?string $body): array;
}
