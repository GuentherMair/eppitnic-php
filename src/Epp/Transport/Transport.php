<?php

namespace Eppitnic\Epp\Transport;

/**
 * The HTTP round-trip Client depends on, narrowed to the methods it calls, so
 * the test suite can substitute one. Not a general-purpose abstraction:
 * everything else Curl offers is set at construction and never consulted again.
 *
 * @category    Net
 * @package     Eppitnic\Epp\Transport\Transport
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
interface Transport
{
    /**
     * @param string|null $postFields the request body to send
     * @return string the raw response body
     */
    public function query(?string $postFields = null): string;

    /**
     * @return int the HTTP status code of the last query()
     */
    public function getHttpStatus(): int;

    /**
     * @return string the raw response headers of the last query()
     */
    public function getHttpHeaders(): string;

    /**
     * @return string the transport-level error of the last query(), or '' if it
     *                succeeded
     */
    public function getHttpError(): string;

    /**
     * Seed the cookie jar a query() sends, e.g. from a session kept alive
     * across processes -- see Eppitnic\Service\SessionState.
     *
     * @param array<string, string> $cookies name => value
     */
    public function setCookies(array $cookies): void;

    /**
     * @return array<string, string> the current jar, name => value -- what was
     *         seeded via setCookies() plus anything a query() since received
     */
    public function getCookies(): array;
}
