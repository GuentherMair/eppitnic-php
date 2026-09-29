<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Api\Auth;
use Eppitnic\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * The JWT names this application, and a TOTP secret shows the host it was
 * enrolled at, so several installations tell apart in an authenticator.
 */
final class AuthTokenClaimsTest extends TestCase
{
    protected function setUp(): void {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);
    }

    protected function tearDown(): void {
        Config::reset();
    }

    public function testTheTokenNamesEppitnic(): void {
        $token = Auth::issueToken(['id' => 1])['token'];

        $claims = JWT::decode($token, new Key('test-signing-key-for-this-suite-only', 'HS256'));

        $this->assertSame('eppitnic', $claims->iss);
        $this->assertSame('eppitnic API', $claims->sub);
    }

    public function testTheTotpIssuerCarriesTheHost(): void {
        $uri = Auth::totpGenerate('mario', 'registrar.example.it')['uri'];

        $this->assertStringContainsString('issuer=eppitnic%20%28registrar.example.it%29', str_replace('+', '%20', $uri));
        $this->assertStringStartsWith('otpauth://totp/eppitnic%20%28registrar.example.it%29%3Amario', str_replace('+', '%20', $uri));
    }

    public function testAnIpv6HostStillMakesAnIssuer(): void {
        $uri = Auth::totpGenerate('mario', '[::1]')['uri'];

        $this->assertStringContainsString('issuer=', $uri);
        $this->assertStringContainsString('eppitnic', $uri);
    }
}
