<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\PowerDns\DryRunHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * `DryRunHttpClient` answers `Api` itself, so `pdns sync --dry-run` runs the
 * real create/update/delete logic with nothing reaching a server. Covered on
 * its own so `PdnsSyncCommand`'s own tests can stick to a `FakeHttpClient`.
 */
final class DryRunHttpClientTest extends TestCase
{
    public function testAMissingZoneReads404UntilItIsCreated(): void {
        $client = new DryRunHttpClient();

        $before = $client->request('GET', 'http://ns1:8081/api/v1/servers/localhost/zones/example.it.', [], null);
        $this->assertSame(404, $before['status']);

        $client->request('POST', 'http://ns1:8081/api/v1/servers/localhost/zones', [], '{"name":"example.it."}');

        $after = $client->request('GET', 'http://ns1:8081/api/v1/servers/localhost/zones/example.it.', [], null);
        $this->assertSame(200, $after['status']);
    }

    public function testPostReturns201(): void {
        $client = new DryRunHttpClient();

        $response = $client->request('POST', 'http://ns1:8081/api/v1/servers/localhost/zones', [], '{"name":"example.it."}');

        $this->assertSame(201, $response['status']);
    }

    public function testPatchAndDeleteReturn204(): void {
        $client = new DryRunHttpClient();

        $patch = $client->request('PATCH', 'http://ns1:8081/api/v1/servers/localhost/zones/example.it.', [], '{}');
        $delete = $client->request('DELETE', 'http://ns1:8081/api/v1/servers/localhost/zones/example.it.', [], null);

        $this->assertSame(204, $patch['status']);
        $this->assertSame(204, $delete['status']);
    }

    public function testEveryRequestIsRecordedInOrder(): void {
        $client = new DryRunHttpClient();

        $client->request('GET', 'http://ns1:8081/.../zones/example.it.', [], null);
        $client->request('POST', 'http://ns1:8081/.../zones', [], '{"name":"example.it."}');

        $sent = $client->sentRequests();
        $this->assertCount(2, $sent);
        $this->assertSame('GET', $sent[0]['method']);
        $this->assertSame('POST', $sent[1]['method']);
        $this->assertSame('{"name":"example.it."}', $sent[1]['body']);
    }

    public function testANullBodyIsRecordedAsNull(): void {
        $client = new DryRunHttpClient();

        $client->request('DELETE', 'http://ns1:8081/.../zones/example.it.', [], null);

        $this->assertNull($client->sentRequests()[0]['body']);
    }

    /** two different zones never share the create-tracking state */
    public function testCreatingOneZoneDoesNotMakeAnotherReadAsExisting(): void {
        $client = new DryRunHttpClient();

        $client->request('POST', 'http://ns1:8081/.../zones', [], '{"name":"one.it."}');
        $response = $client->request('GET', 'http://ns1:8081/.../zones/two.it.', [], null);

        $this->assertSame(404, $response['status']);
    }
}
