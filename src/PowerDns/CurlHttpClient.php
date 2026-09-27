<?php

namespace Eppitnic\PowerDns;

/**
 * The real HttpClient, over curl (already a hard dependency -- see
 * composer.json). TLS is verified; both timeouts are short, since a
 * `pdns sync` run must not hang on an unreachable server.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $connectTimeout = 5,
        private int $timeout = 15
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            return ['status' => 0, 'body' => '', 'error' => $error];
        }

        // no curl_close(): a no-op since PHP 8.0, deprecated in 8.5
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'body' => (string) $response, 'error' => ''];
    }
}
