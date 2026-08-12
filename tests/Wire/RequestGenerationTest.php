<?php

namespace Net\EPP\Tests\Wire;

use Net\EPP\Tests\Support\CommandCatalog;
use Net\EPP\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Snapshot test over every EPP request the codebase generates.
 *
 * The generator may be replaced wholesale as long as these bytes do not move.
 * Deliberately a snapshot rather than a set of hand-written expectations:
 * hand-written expectations for 29 requests would themselves be the thing most
 * likely to contain the mistake.
 *
 * To (re)generate the fixtures after an intentional change:
 *
 *     UPDATE_FIXTURES=1 vendor/bin/phpunit --testsuite wire
 *
 * and then *read the diff*. An unreviewed regeneration turns this test from a
 * safety net into a rubber stamp.
 */
final class RequestGenerationTest extends EppTestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../fixtures/wire';

    public static function commandProvider(): array {
        $cases = [];
        foreach (array_keys(CommandCatalog::all()) as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    #[DataProvider('commandProvider')]
    public function testRequestMatchesFixture(string $name): void {
        $driver = CommandCatalog::all()[$name];
        $object = $driver($this->nic, $this->transport);

        $this->assertNotEmpty(
            $this->transport->requests,
            "'{$name}' sent no request at all -- the driver in CommandCatalog is not exercising the command"
        );

        $actual = self::normalize($this->transport->lastRequest());
        $path = self::FIXTURE_DIR . '/' . $name . '.xml';

        if (getenv('UPDATE_FIXTURES')) {
            if ( ! is_dir(self::FIXTURE_DIR)) {
                mkdir(self::FIXTURE_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertFileExists(
            $path,
            "No fixture for '{$name}'. Run with UPDATE_FIXTURES=1 to record one, then review the diff."
        );
        $this->assertSame(trim(file_get_contents($path)), $actual, "generated request for '{$name}' changed");
    }

    /**
     * Well-formedness is asserted separately from the snapshot so that a
     * generator producing broken XML fails with a parser error naming the
     * problem, rather than a wall-of-text string diff.
     */
    #[DataProvider('commandProvider')]
    public function testRequestIsWellFormed(string $name): void {
        $driver = CommandCatalog::all()[$name];
        $driver($this->nic, $this->transport);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($this->transport->lastRequest());
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, "'{$name}' generated XML that does not parse");
        $this->assertSame([], array_map(fn($e) => trim($e->message), $errors), "'{$name}' generated XML with parser errors");
    }
}
