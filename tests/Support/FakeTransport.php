<?php

namespace Net\EPP\Tests\Support;

use Net\EPP\Epp\Transport\Transport;

/**
 * A Transport that never leaves the process: it records every request body it
 * is handed and answers from a queue of canned responses.
 *
 * Recording the request is the point -- the wire-format tests assert on what
 * the code *generated*, which is exactly the thing an XML-layer rewrite must
 * not change.
 */
final class FakeTransport implements Transport
{
    /** @var string[] every request body passed to query(), in order */
    public array $requests = [];

    /** @var array<int, array{body: string|\Closure, status: int, headers: string}> */
    private array $responses = [];

    private int $status = 200;
    private string $headers = "HTTP/1.1 200 OK\r\nContent-Type: text/xml; charset=UTF-8\r\n\r\n";
    private string $error = '';

    /**
     * queue one response to be returned by the next query()
     *
     * @param string $body the response body to answer with
     * @param int $status the HTTP status to report
     */
    public function queue(string $body, int $status = 200): self {
        $this->responses[] = ['body' => $body, 'status' => $status, 'headers' => $this->headers];
        return $this;
    }

    /**
     * queue a response computed from the request that asks for it.
     *
     * For the cases where the answer genuinely depends on what was sent -- a
     * registry accepting one password and refusing another -- which a fixed
     * queue cannot express.
     *
     * @param \Closure(string): string $responder given the request body, returns the response body
     */
    public function queueCallback(\Closure $responder, int $status = 200): self {
        $this->responses[] = ['body' => $responder, 'status' => $status, 'headers' => $this->headers];
        return $this;
    }

    /**
     * queue the same response for every query() -- for commands whose response
     * a given test doesn't care about
     */
    public function queueAll(string $body, int $count = 10, int $status = 200): self {
        for ($i = 0; $i < $count; $i++) {
            $this->queue($body, $status);
        }
        return $this;
    }

    /**
     * @return string the most recent request body, or '' if none was sent
     */
    public function lastRequest(): string {
        return end($this->requests) ?: '';
    }

    public function query(?string $postFields = null): string {
        $this->requests[] = (string) $postFields;

        $response = array_shift($this->responses);
        if ($response === null) {
            // an empty body is what a dead connection looks like to Client, so
            // tests that forgot to queue a response fail the way production
            // would rather than with a confusing type error further down
            $this->status = 500;
            return '';
        }

        $this->status = $response['status'];
        $this->headers = $response['headers'];

        return $response['body'] instanceof \Closure
            ? ($response['body'])((string) $postFields)
            : $response['body'];
    }

    public function getHttpStatus(): int {
        return $this->status;
    }

    public function getHttpHeaders(): string {
        return $this->headers;
    }

    public function getHttpError(): string {
        return $this->error;
    }
}
