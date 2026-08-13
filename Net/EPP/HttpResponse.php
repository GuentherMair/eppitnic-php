<?php

namespace Net\EPP;

/**
 * One HTTP exchange with the registry.
 *
 * Was a bare `array|null` keyed by four strings, declared as `// HTTP response
 * string` -- which it had not been for some time. Every reader spelled out
 * `$this->result['code'] ?? ''` because nothing guaranteed the keys, and
 * nothing said what type any of them held.
 *
 * @category    Net
 * @package     Net\EPP\HttpResponse
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class HttpResponse
{
    /**
     * @param string $body the response body, empty when the request failed outright
     * @param int $code the HTTP status
     * @param string $headers the raw response headers
     * @param string $error the transport's own error, empty when there was none
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $code = 0,
        public readonly string $headers = '',
        public readonly string $error = ''
    ) {}

    /**
     * Whether the registry answered at the HTTP level. Says nothing about what
     * it answered: an EPP error is a perfectly good 200.
     */
    public function ok(): bool {
        return $this->code >= 200 && $this->code < 300;
    }
}
