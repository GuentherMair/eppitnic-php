<?php

namespace Eppitnic\Tests\Support;

/**
 * The registry's XML schemas (xsd/), assembled into one validation context and
 * shared by the tests that validate generated requests and those that hand-build
 * responses: a sample written from a schema is worth nothing unchecked.
 */
final class RegistrySchemas
{
    /**
     * Exactly one file per namespace: importing both a wrapper and what it
     * includes makes libxml treat one namespace as two and drop a schema.
     * extdom/extepp-1.0 are absent on purpose -- both ends speak 2.0.
     */
    public const SCHEMAS = [
        'urn:ietf:params:xml:ns:epp-1.0'            => 'epp-1.0.xsd',
        'urn:ietf:params:xml:ns:eppcom-1.0'         => 'eppcom-1.0.xsd',
        'urn:ietf:params:xml:ns:domain-1.0'         => 'domain-itnic.xsd',
        'urn:ietf:params:xml:ns:contact-1.0'        => 'contact-1.0.xsd',
        'urn:ietf:params:xml:ns:host-1.0'           => 'host-1.0.xsd',
        'urn:ietf:params:xml:ns:rgp-1.0'            => 'rgp-itnic.xsd',
        'urn:ietf:params:xml:ns:secDNS-1.1'         => 'secDNS-1.1.xsd',
        'http://www.nic.it/ITNIC-EPP/extcon-1.0'    => 'extcon-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extdom-2.0'    => 'extdom-2.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extepp-2.0'    => 'extepp-2.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extsecDNS-1.0' => 'extsecDNS-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extgovcon-1.0' => 'extgovcon-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extgovdom-1.0' => 'extgovdom-1.0.xsd',
    ];

    /**
     * realpath()'d deliberately: the schemas import each other relatively and
     * libxml keys "already imported" on the literal URI, so an unnormalised path
     * makes it treat one schema as two and drop one.
     */
    public static function dir(): string {
        return realpath(__DIR__ . '/../../xsd');
    }

    /**
     * @return string an XSD importing every schema above
     */
    public static function catalog(): string {
        $imports = '';
        foreach (self::SCHEMAS as $ns => $file) {
            $imports .= sprintf(
                '  <xs:import namespace="%s" schemaLocation="%s"/>' . "\n",
                htmlspecialchars($ns, ENT_XML1),
                htmlspecialchars('file://' . self::dir() . '/' . $file, ENT_XML1)
            );
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<xs:schema xmlns:xs=\"http://www.w3.org/2001/XMLSchema\">\n"
             . $imports
             . "</xs:schema>\n";
    }

    /**
     * @param string $xml the document to validate
     * @return string[] validation errors; empty when the document is valid
     */
    public static function validate(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $dom->schemaValidateSource(self::catalog());

        $errors = array_map(fn($e) => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $errors;
    }
}
