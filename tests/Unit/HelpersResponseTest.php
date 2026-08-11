<?php

namespace Net\EPP\Tests\Unit;

use Net\EPP\Helpers;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * The response helpers are used by every route, so a mistake in them is a
 * mistake in the whole API at once -- which is exactly why they are worth
 * testing directly, even though each is only a few lines.
 */
final class HelpersResponseTest extends TestCase
{
    private function response(): \Psr\Http\Message\ResponseInterface {
        return (new ResponseFactory())->createResponse();
    }

    public function testJsonWritesBodyStatusAndContentType(): void {
        $response = Helpers::json($this->response(), ['domain' => 'example.it'], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"domain":"example.it"}', (string) $response->getBody());
    }

    public function testJsonDefaultsTo200(): void {
        $this->assertSame(200, Helpers::json($this->response(), [])->getStatusCode());
    }

    /**
     * An empty array must serialize as a JSON object or array consistently --
     * never as PHP's `[]`-vs-`{}` surprise mid-payload. Pinned so a future
     * change to json() flags (JSON_FORCE_OBJECT, say) is a deliberate one.
     */
    public function testJsonEncodesEmptyArrayAsArray(): void {
        $this->assertSame('{"domains":[]}', (string) Helpers::json($this->response(), ['domains' => []])->getBody());
    }

    /**
     * UTF-8 must survive: registrant names and organisations routinely carry
     * accented characters, and a route that mangles them is a data bug the
     * client sees, not a cosmetic one.
     */
    public function testJsonPreservesUtf8Payloads(): void {
        $body = (string) Helpers::json($this->response(), ['org' => 'Grüße & Co'])->getBody();

        $this->assertSame(['org' => 'Grüße & Co'], json_decode($body, true));
    }
}
