<?php

namespace Eppitnic\Api;

use Psr\Http\Message\ResponseInterface as HttpResponse;

/**
 * The one shape every route in this API answers in.
 *
 * Named Json rather than Response because every route file already imports
 * PSR-7's ResponseInterface under that name, and aliasing the type hint in
 * each closure signature would be a worse trade than naming this for what it
 * writes.
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
     * write $body as the JSON response, with the status and content type every
     * route in this application returns.
     *
     * Exists because the three-line write/withStatus/withHeader incantation was
     * repeated at 129 call sites, and a route that got one of the three subtly
     * wrong -- a missing charset, a 200 on an error path -- looked exactly like
     * the 128 that got it right.
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
