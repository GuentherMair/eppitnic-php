<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Epp\Client;
use Eppitnic\Config;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a Client without a database or a network. The
 * settings below are fixed, not a copy of anyone's configuration: a test whose
 * clTRID prefix varies per run cannot assert on the bytes it produced.
 */
abstract class EppTestCase extends TestCase
{
    protected Client $nic;
    protected FakeTransport $transport;

    /**
     * settings Client's constructor reads -- see Client::__construct() --
     * plus the three keepalive settings EppSession::run() reads via
     * SessionState regardless: Config::get() throws on an unknown key, and
     * every test through withSession()/EppSession::run() would fail on it
     * otherwise. Off, matching the schema's own defaults, so this changes no
     * existing test's behaviour.
     */
    protected const SETTINGS = [
        'region' => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT', 'lc_time' => 'italian'],
        'epp' => [
            'server'             => 'https://epp.pubtest.nic.it',
            'server_deleted'     => 'https://epp-deleted.nic.it',
            'port'               => null,
            'interface'          => '',
            'username'           => 'TEST-REG',
            'password'           => 'test-password',
            'lang'               => 'en',
            'cl_trid_prefix'     => 'TEST',
            'lastPasswordUpdate' => 0,
        ],
        'certificatefile'   => null,
        'debugfile'         => '',
        'dnssec'            => ['active' => 1, 'algorithm' => 10, 'digesttype' => 2],
        'keepalive'         => false,
        'session_cookies'   => [],
        'session_timestamp' => 0,
    ];

    protected function setUp(): void {
        parent::setUp();

        Config::loadForTesting(static::SETTINGS);

        $this->nic = new Client();
        $this->transport = new FakeTransport();
        $this->nic->setTransport($this->transport);

        // clTRID embeds time() and a random suffix, so normalize() strips it
        // rather than freezing it: the generator's job is to place the element
        // correctly, not to invent a specific id
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    /**
     * Make a generated request comparable across runs: strip the volatile
     * clTRID and normalize whitespace, so the assertion is about structure and
     * values rather than one generator's formatting.
     *
     * @param string $xml the raw request body
     * @return string a canonical form suitable for string comparison
     */
    protected static function normalize(string $xml): string {
        $xml = preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>NORMALIZED</clTRID>', $xml);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if ( ! @$dom->loadXML($xml)) {
            return trim($xml); // malformed: let the caller's assertion report it
        }

        // Attribute order carries no meaning in XML, and the registry does not
        // see it, so a snapshot that depends on it fails for cosmetic reasons
        // -- which is how it stops being read.
        self::sortAttributes($dom->documentElement);

        return trim($dom->saveXML());
    }

    private static function sortAttributes(\DOMElement $element): void {
        $attributes = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue;
            $element->removeAttributeNode($attribute);
        }
        ksort($attributes);
        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                self::sortAttributes($child);
            }
        }
    }
}
