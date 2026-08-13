<?php

namespace Net\EPP\Epp\Transport;

/**
 * The HTTP round-trip Client depends on, narrowed to the four methods it
 * actually calls (Client::sendRequest()). Curl is the production
 * implementation; the test suite substitutes its own so request generation
 * and response parsing can be exercised without a registry -- and without a
 * network -- at all.
 *
 * Deliberately not a general-purpose HTTP abstraction: everything else Curl
 * offers (redirects, user agent, referer, binary transfer) is configured by
 * Client at construction time and never consulted again, so it has no place
 * in the seam.
 *
 * @category    Net
 * @package     Net\EPP\Epp\Transport\Transport
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
     * @return string the transport-level error of the last query(), or '' if it succeeded
     */
    public function getHttpError(): string;
}
