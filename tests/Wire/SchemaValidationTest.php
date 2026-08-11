<?php

namespace Net\EPP\Tests\Wire;

use Net\EPP\Tests\Support\CommandCatalog;
use Net\EPP\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Validates every generated request against the registry's own XML schemas
 * (xsd/), which is a far stronger statement than "it parses": it checks
 * element order, cardinality, value facets and namespace correctness -- the
 * exact class of mistake that otherwise surfaces as a 2001 "Command syntax
 * error" from the registry, at the worst possible moment.
 *
 * A request whose extensions live in a namespace with no schema on disk is
 * skipped with a message naming the missing file, rather than passing quietly.
 * A silent pass here would be worse than no test: it would report coverage
 * that does not exist.
 */
final class SchemaValidationTest extends EppTestCase
{
    /**
     * realpath()'d, deliberately: the registry schemas import each other by
     * relative location, and libxml keys its "already imported" bookkeeping on
     * the literal URI. Handing it '…/tests/Wire/../../xsd/epp-1.0.xsd' while
     * contact-1.0.xsd imports '…/xsd/epp-1.0.xsd' makes it treat one schema as
     * two, and it then drops one of them with a warning that buries the real
     * validation errors.
     */
    private static function xsdDir(): string {
        return realpath(__DIR__ . '/../../xsd');
    }

    /**
     * Namespaces a request may use, mapped to the schema file that defines
     * them. A null value means "known to be used by this codebase, but no
     * schema has been provided" -- see testSchemaSetIsComplete().
     *
     * Exactly one file per namespace. domain-1.0 and rgp-1.0 are deliberately
     * reached through nic.it's own domain-itnic.xsd / rgp-itnic.xsd, which
     * <include> the IETF originals and add the global <domain:status> and
     * <rgp:rgpStatus> element declarations the registry's poll messages put
     * directly inside <extdom:targetStatus> (see Session::parsePollReq()).
     * Importing both the wrapper and the file it includes would make libxml
     * treat one namespace as two and silently drop a schema.
     *
     * extdom-1.0.xsd and extepp-1.0.xsd are present in xsd/ but intentionally
     * absent here: the code and the live registry both speak the 2.0 versions.
     */
    private const SCHEMAS = [
        'urn:ietf:params:xml:ns:epp-1.0'              => 'epp-1.0.xsd',
        'urn:ietf:params:xml:ns:eppcom-1.0'           => 'eppcom-1.0.xsd',
        'urn:ietf:params:xml:ns:domain-1.0'           => 'domain-itnic.xsd',
        'urn:ietf:params:xml:ns:contact-1.0'          => 'contact-1.0.xsd',
        'urn:ietf:params:xml:ns:host-1.0'             => 'host-1.0.xsd',
        'urn:ietf:params:xml:ns:rgp-1.0'              => 'rgp-itnic.xsd',
        'urn:ietf:params:xml:ns:secDNS-1.1'           => 'secDNS-1.1.xsd',
        'http://www.nic.it/ITNIC-EPP/extcon-1.0'      => 'extcon-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extdom-2.0'      => 'extdom-2.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extepp-2.0'      => 'extepp-2.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extsecDNS-1.0'   => 'extsecDNS-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extgovcon-1.0'   => 'extgovcon-1.0.xsd',
        'http://www.nic.it/ITNIC-EPP/extgovdom-1.0'   => 'extgovdom-1.0.xsd',
    ];

    /**
     * An XSD whose only job is to pull every available schema into one
     * validation context, so a document mixing epp-1.0 with domain-1.0 and
     * extcon-1.0 can be validated in a single pass.
     */
    private static function catalogSchema(): string {
        $imports = '';
        foreach (self::SCHEMAS as $ns => $file) {
            if ($file === null) {
                continue;
            }
            $path = self::xsdDir() . '/' . $file;
            $imports .= sprintf(
                '  <xs:import namespace="%s" schemaLocation="%s"/>' . "\n",
                htmlspecialchars($ns, ENT_XML1),
                htmlspecialchars('file://' . $path, ENT_XML1)
            );
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<xs:schema xmlns:xs=\"http://www.w3.org/2001/XMLSchema\">\n"
             . $imports
             . "</xs:schema>\n";
    }

    /**
     * Compile the catalog on its own and report what libxml made of it.
     *
     * Without this, a single unresolvable type reference inside one registry
     * schema fails every request in the suite, and 27 identical failures say
     * nothing about the 27 requests. XMLReader::setSchema() is used rather
     * than DOMDocument::schemaValidateSource() precisely because it compiles
     * the schema without also validating a document against it, which is the
     * separation being drawn here.
     *
     * @return string[] compile errors; empty when the schema set is sound
     */
    private static function catalogCompileErrors(): array {
        static $errors = null;
        if ($errors !== null) {
            return $errors;
        }

        $file = tempnam(sys_get_temp_dir(), 'eppitnic-catalog-') . '.xsd';
        file_put_contents($file, self::catalogSchema());

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        $reader->XML('<probe/>');
        @$reader->setSchema($file);

        $errors = array_map(fn($e) => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $reader->close();
        unlink($file);

        // "this document isn't valid against the schema" is expected -- <probe/>
        // is not an EPP document. Only schema-construction problems matter here.
        $errors = array_values(array_filter(
            $errors,
            fn($e) => ! str_contains($e, "No matching global declaration available for the validation root")
        ));

        return $errors;
    }

    public static function commandProvider(): array {
        $cases = [];
        foreach (array_keys(CommandCatalog::all()) as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    /**
     * @return string[] every namespace URI referenced anywhere in $xml
     */
    private static function namespacesUsed(string $xml): array {
        preg_match_all('/xmlns(?::[A-Za-z0-9_-]+)?="([^"]+)"/', $xml, $m);
        return array_values(array_unique($m[1]));
    }

    #[DataProvider('commandProvider')]
    public function testRequestValidatesAgainstRegistrySchemas(string $name): void {
        if ( ! empty(self::catalogCompileErrors())) {
            $this->markTestSkipped('the registry schema set does not compile -- see testSchemaCatalogCompiles()');
        }

        $path = __DIR__ . '/../fixtures/wire/' . $name . '.xml';
        $this->assertFileExists($path, "record the wire fixture first (UPDATE_FIXTURES=1)");
        $xml = file_get_contents($path);

        $unsatisfied = [];
        foreach (self::namespacesUsed($xml) as $ns) {
            if ($ns === 'http://www.w3.org/2001/XMLSchema-instance') {
                continue;
            }
            if ( ! array_key_exists($ns, self::SCHEMAS)) {
                $this->fail("'{$name}' uses namespace '{$ns}', which this test does not know about at all");
            }
            if (self::SCHEMAS[$ns] === null) {
                $unsatisfied[] = $ns;
            }
        }

        if ( ! empty($unsatisfied)) {
            $this->markTestSkipped(
                "'{$name}' cannot be validated: no schema in xsd/ for " . implode(', ', $unsatisfied)
            );
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $valid = $dom->schemaValidateSource(self::catalogSchema());
        $errors = array_map(
            fn($e) => trim($e->message) . ' (line ' . $e->line . ')',
            libxml_get_errors()
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue(
            $valid,
            "'{$name}' is not schema-valid:\n  - " . implode("\n  - ", $errors) . "\n\n{$xml}"
        );
    }

    /**
     * The registry's schemas must form a self-consistent set on their own,
     * independently of anything this codebase generates.
     */
    public function testSchemaCatalogCompiles(): void {
        $this->assertSame(
            [],
            self::catalogCompileErrors(),
            "the schemas in xsd/ do not compile as a set:\n  - "
                . implode("\n  - ", self::catalogCompileErrors())
                . "\n\nThis is a problem with the schema files, not with the generated XML."
        );
    }

    /**
     * Fails while any namespace this codebase actually emits has no schema on
     * disk. Not a nicety: every such gap is a command whose structure nothing
     * verifies before the registry rejects it.
     */
    public function testSchemaSetIsComplete(): void {
        $missing = array_keys(array_filter(self::SCHEMAS, fn($f) => $f === null));

        $this->assertSame(
            [],
            $missing,
            "xsd/ is missing schemas for namespaces this codebase emits:\n  - " . implode("\n  - ", $missing)
                . "\n\nNote xsd/ currently holds extdom-1.0 and extepp-1.0, but the code and the"
                . "\nlive registry greeting both use extdom-2.0 and extepp-2.0."
        );
    }
}
