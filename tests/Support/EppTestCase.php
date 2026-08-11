<?php

namespace Net\EPP\Tests\Support;

use Net\EPP\Client;
use Net\EPP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a Client without a database or a network.
 *
 * The settings below are fixed values, not a copy of anyone's configuration:
 * a test that generates a different clTRID prefix or talks to a different
 * server every time cannot assert on the bytes it produced.
 */
abstract class EppTestCase extends TestCase
{
    protected Client $nic;
    protected FakeTransport $transport;

    /**
     * settings Client's constructor reads -- see Client::__construct()
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
        'certificatefile' => null,
        'debugfile'       => '',
        'cookie_dir'      => null,
        'dnssec'          => ['active' => 1, 'algorithm' => 10, 'digesttype' => 2],
        'smarty'          => ['use_sub_dirs' => null, 'template_dir' => null, 'config_dir' => null, 'compile_dir' => null, 'cache_dir' => null],
    ];

    protected function setUp(): void {
        parent::setUp();

        Config::loadForTesting(static::SETTINGS);

        $this->nic = new Client();
        $this->transport = new FakeTransport();
        $this->nic->setTransport($this->transport);

        // clTRID embeds time() and a random suffix, so it differs on every run.
        // Fixture comparison normalizes it away (see normalize()) rather than
        // freezing it: the generator's job is to place the element correctly,
        // not to invent a specific id.
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    /**
     * Make a generated request comparable across runs: strip the volatile
     * clTRID content and normalize insignificant whitespace, so the assertion
     * is about element structure and values rather than the formatting a
     * particular generator happens to emit.
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
        return trim($dom->saveXML());
    }
}
