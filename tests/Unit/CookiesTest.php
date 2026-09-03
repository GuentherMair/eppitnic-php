<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Transport\Cookies;
use PHPUnit\Framework\TestCase;

/**
 * Cookies::parse()/header() -- the in-memory replacement for cURL's cookie
 * file (see Curl's class docblock). Pure and static, so this needs neither a
 * transport nor a database.
 */
final class CookiesTest extends TestCase
{
    public function testParsesMultipleSetCookieHeaders(): void {
        $headers = "HTTP/1.1 200 OK\r\n"
            . "Set-Cookie: JSESSIONID=abc123; Path=/; HttpOnly\r\n"
            . "Set-Cookie: OTHER=xyz; Path=/\r\n"
            . "Content-Type: text/xml\r\n\r\n";

        $this->assertSame(
            ['JSESSIONID' => 'abc123', 'OTHER' => 'xyz'],
            Cookies::parse($headers)
        );
    }

    public function testParsesACookieWithNoAttributes(): void {
        // no ';' at all -- terminated only by the line ending
        $headers = "HTTP/1.1 200 OK\r\nSet-Cookie: JSESSIONID=abc123\r\n\r\n";

        $this->assertSame(['JSESSIONID' => 'abc123'], Cookies::parse($headers));
    }

    public function testMatchesCaseInsensitively(): void {
        $headers = "HTTP/1.1 200 OK\r\nset-cookie: JSESSIONID=abc123; Path=/\r\n\r\n";

        $this->assertSame(['JSESSIONID' => 'abc123'], Cookies::parse($headers));
    }

    public function testValueContainingAnEqualsSignIsKeptWhole(): void {
        $headers = "HTTP/1.1 200 OK\r\nSet-Cookie: token=a=b=c; Path=/\r\n\r\n";

        $this->assertSame(['token' => 'a=b=c'], Cookies::parse($headers));
    }

    public function testEmptyHeaderBlockYieldsNoCookies(): void {
        $this->assertSame([], Cookies::parse(''));
    }

    public function testNoSetCookieHeaderYieldsNoCookies(): void {
        $headers = "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n";

        $this->assertSame([], Cookies::parse($headers));
    }

    /**
     * A header block whose Set-Cookie starts at byte 0 -- strpos() there
     * returns 0, which a `!$s` check (the brief's original sketch) would
     * misread as "not found" and stop parsing.
     */
    public function testSetCookieAtTheVeryStartOfTheBlockIsFound(): void {
        $headers = "Set-Cookie: JSESSIONID=abc123; Path=/\r\n\r\n";

        $this->assertSame(['JSESSIONID' => 'abc123'], Cookies::parse($headers));
    }

    public function testALaterCookieOfTheSameNameOverwritesTheEarlierOne(): void {
        $headers = "Set-Cookie: JSESSIONID=old; Path=/\r\nSet-Cookie: JSESSIONID=new; Path=/\r\n\r\n";

        $this->assertSame(['JSESSIONID' => 'new'], Cookies::parse($headers));
    }

    public function testHeaderBuildsTheCookieRequestHeaderValue(): void {
        $this->assertSame('a=1; b=2', Cookies::header(['a' => '1', 'b' => '2']));
    }

    public function testHeaderOfAnEmptyJarIsEmptyString(): void {
        $this->assertSame('', Cookies::header([]));
    }
}
