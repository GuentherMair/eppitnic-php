<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\RegistrySchemas;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Validates every generated request against the registry's own schemas -- order,
 * cardinality, facets and namespaces, the mistake that otherwise arrives as a
 * 2001. A request with no schema on disk is skipped by name, never passed.
 */
final class SchemaValidationTest extends EppTestCase
{
    /**
     * Compile the catalog alone and report what libxml made of it: otherwise one
     * unresolvable type reference fails every request, and 27 identical failures
     * say nothing. XMLReader::setSchema() compiles without also validating.
     *
     * @return string[] compile errors; empty when the schema set is sound
     */
    private static function catalogCompileErrors(): array {
        static $errors = null;
        if ($errors !== null) {
            return $errors;
        }

        $file = tempnam(sys_get_temp_dir(), 'eppitnic-catalog-') . '.xsd';
        file_put_contents($file, RegistrySchemas::catalog());

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
            if ( ! array_key_exists($ns, RegistrySchemas::SCHEMAS)) {
                $this->fail("'{$name}' uses namespace '{$ns}', which this test does not know about at all");
            }
            if (RegistrySchemas::SCHEMAS[$ns] === null) {
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
        $valid = $dom->schemaValidateSource(RegistrySchemas::catalog());
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
        $missing = array_keys(array_filter(RegistrySchemas::SCHEMAS, fn($f) => $f === null));

        $this->assertSame(
            [],
            $missing,
            "xsd/ is missing schemas for namespaces this codebase emits:\n  - " . implode("\n  - ", $missing)
                . "\n\nNote xsd/ currently holds extdom-1.0 and extepp-1.0, but the code and the"
                . "\nlive registry greeting both use extdom-2.0 and extepp-2.0."
        );
    }
}
