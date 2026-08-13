<?php

namespace Net\EPP\Tests\Wire;

use Net\EPP\Epp\CheckResult;
use Net\EPP\Epp\Contact;
use Net\EPP\Epp\Domain;
use Net\EPP\Epp\Session;
use Net\EPP\Tests\Support\CommandCatalog;
use Net\EPP\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every parser, against responses that are not what it expects.
 *
 * A parser that walks an unexpected document does not fail usefully: PHP
 * answers a missing SimpleXML child with an empty element, so a chain like
 * `->response->resData->children($ns['domain'])->infData->name` produces
 * warnings and nulls rather than an error, and `count()` on the result is a
 * fatal. Six such sites were found one at a time during this refactoring --
 * in the poll parser, the login credit read, both fetch() methods and both
 * check() methods -- each a real fault, one of them fatal.
 *
 * Finding the seventh by waiting for it is not a plan. This drives every
 * parser with every shape of wrong answer and requires the same thing of all
 * of them: return a failure, set an error, and raise no PHP diagnostic.
 */
final class MalformedResponseTest extends EppTestCase
{
    /**
     * Answers that are unusable whatever was asked: no parser may report
     * success for any of these.
     *
     * @return array<string, array{0: string}>
     */
    public static function unusableResponses(): array {
        return [
            'empty body' => [''],

            'not xml' => ['<html><body>502 Bad Gateway</body></html>'],

            'truncated' => ['<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'],

            'no response element at all' => [<<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"/>
                XML],

            'greeting where a response belongs' => [CommandCatalog::GREETING_RESPONSE],
        ];
    }

    /**
     * Answers that carry a result code but no object payload.
     *
     * These are not malformed for every command -- a <delete> or <logout>
     * succeeds with exactly this shape -- so only the parsers that need data
     * back are required to reject them. Every parser is still required not to
     * raise a diagnostic on them, which is where the six faults lived.
     *
     * @return array<string, array{0: string}>
     */
    public static function payloadlessResponses(): array {
        return [
            'success with no resData' => [CommandCatalog::OK_RESPONSE],

            'success with empty resData' => [<<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                  <response>
                    <result code="1000"><msg>ok</msg></result>
                    <resData/>
                    <trID><svTRID>x</svTRID></trID>
                  </response>
                </epp>
                XML],

            // resData present, but from an object namespace the caller is not
            // asking about -- a contact answer to a domain query
            'resData in the wrong namespace' => [<<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                  <response>
                    <result code="1000"><msg>ok</msg></result>
                    <resData>
                      <contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                        <contact:id>ABCD1234EFGH5678</contact:id>
                      </contact:infData>
                    </resData>
                    <trID><svTRID>x</svTRID></trID>
                  </response>
                </epp>
                XML],

            'extension present but empty' => [<<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                  <response>
                    <result code="1301"><msg>ack to dequeue</msg></result>
                    <msgQ count="1" id="1"><msg>something</msg></msgQ>
                    <extension/>
                    <trID><svTRID>x</svTRID></trID>
                  </response>
                </epp>
                XML],
        ];
    }

    /**
     * Every call that parses a response, named so a failure says which.
     *
     * @return array<string, array{0: callable}>
     */
    public static function parsers(): array {
        return [
            //                             call                                                          needs a payload
            'Session::hello'          => [fn($nic) => (new Session($nic))->hello(),                       true],
            'Session::login'          => [fn($nic) => (new Session($nic))->login(),                       false],
            'Session::logout'         => [fn($nic) => (new Session($nic))->logout(),                      false],
            'Session::poll'           => [fn($nic) => (new Session($nic))->poll(false, 'req'),            false],
            'Domain::check'           => [fn($nic) => (new Domain($nic))->check('example-one.it'),        true],
            'Domain::fetch'           => [fn($nic) => (new Domain($nic))->fetch('example-one.it'),        true],
            'Domain::delete'          => [fn($nic) => (new Domain($nic))->delete('example-one.it'),       false],
            'Domain::restore'         => [fn($nic) => (new Domain($nic))->restore('example-one.it'),      false],
            'Domain::transferStatus'  => [fn($nic) => (new Domain($nic))->transferStatus('example-one.it', 'AUTH'), true],
            'Domain::transfer'        => [fn($nic) => (new Domain($nic))->transfer('example-one.it', 'AUTH'), false],
            'Contact::check'          => [fn($nic) => (new Contact($nic))->check('ABCD1234EFGH5678'),     true],
            'Contact::fetch'          => [fn($nic) => (new Contact($nic))->fetch('ABCD1234EFGH5678'),     true],
            'Contact::delete'         => [fn($nic) => (new Contact($nic))->delete('ABCD1234EFGH5678'),    false],
        ];
    }

    /**
     * Every parser against every bad answer of either kind.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function everyCombination(): array {
        $cases = [];
        $all = self::unusableResponses() + self::payloadlessResponses();

        foreach (self::parsers() as $name => [$call, $needsPayload]) {
            foreach ($all as $shape => [$response]) {
                $cases["{$name} / {$shape}"] = [$call, $response];
            }
        }
        return $cases;
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function unusableCombinations(): array {
        $cases = [];
        foreach (self::parsers() as $name => [$call, $needsPayload]) {
            foreach (self::unusableResponses() as $shape => [$response]) {
                // a greeting is the correct answer to <hello>, and only to it
                if ($name === 'Session::hello' && $shape === 'greeting where a response belongs') {
                    continue;
                }
                $cases["{$name} / {$shape}"] = [$call, $response];
            }
        }
        return $cases;
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function payloadlessCombinations(): array {
        $cases = [];
        foreach (self::parsers() as $name => [$call, $needsPayload]) {
            if ( ! $needsPayload) {
                continue;
            }
            foreach (self::payloadlessResponses() as $shape => [$response]) {
                $cases["{$name} / {$shape}"] = [$call, $response];
            }
        }
        return $cases;
    }

    /**
     * The invariant that holds for every parser and every bad answer: no PHP
     * diagnostic and no fatal.
     *
     * This is the one that matters. The suite runs with failOnWarning,
     * failOnNotice and failOnDeprecation, so a single warning fails this on
     * its own -- and a warning is exactly how each of the six faults showed
     * itself before it was understood.
     */
    #[DataProvider('everyCombination')]
    public function testParserRaisesNoDiagnostic(callable $call, string $response): void {
        $this->transport->queue($response);

        $call($this->nic);

        // reaching here means no fatal, and no diagnostic was raised
        $this->addToAssertionCount(1);
    }

    /**
     * An answer nothing can be made of is never a success.
     */
    #[DataProvider('unusableCombinations')]
    public function testUnusableAnswerIsNeverSuccess(callable $call, string $response): void {
        $this->transport->queue($response);

        $result = $call($this->nic);

        if ($result instanceof CheckResult) {
            $this->assertFalse($result->answered(), 'an unusable answer was reported as an availability answer');
            return;
        }

        $this->assertNotTrue($result, 'an unusable answer was reported as success');
    }

    /**
     * A parser that needs data back must say so when there is none, rather
     * than returning an object full of empty strings.
     */
    #[DataProvider('payloadlessCombinations')]
    public function testPayloadParserRejectsAnEmptyAnswer(callable $call, string $response): void {
        $this->transport->queue($response);

        $result = $call($this->nic);

        // check() reports through CheckResult, the others through a bare false
        if ($result instanceof CheckResult) {
            $this->assertFalse($result->answered(), 'a payload-less answer was accepted as an availability answer');
            $this->assertNotSame('', $result->error(), 'the failure carries no explanation');
            return;
        }

        $this->assertFalse($result, 'a payload-less answer was accepted: ' . var_export($result, true));
    }
}
