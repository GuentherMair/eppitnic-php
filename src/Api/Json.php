<?php

namespace Eppitnic\Api;

use Psr\Http\Message\ResponseInterface as HttpResponse;

/**
 * The one shape every route in this API answers in. Named Json, not Response,
 * because every route file already imports PSR-7's ResponseInterface as that.
 *
 * @category    Net
 * @package     Eppitnic\Api\Json
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Json
{
    // -----------------------------------------------------------------
    // HTTP responses
    // -----------------------------------------------------------------

    /**
     * Write $body as the JSON response every route returns. The three-line
     * write/withStatus/withHeader was repeated at 129 sites, where one getting
     * it subtly wrong looked exactly like the 128 that did not.
     *
     * @param HttpResponse $response the response to write to
     * @param array $body the payload, JSON-encoded as-is
     * @param int $status the HTTP status code
     * @return HttpResponse the response, ready to return
     */
    public static function response(HttpResponse $response, array $body, int $status = 200): HttpResponse {
        $response->getBody()->write(json_encode($body));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
