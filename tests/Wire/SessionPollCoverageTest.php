<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Session;
use PHPUnit\Framework\TestCase;

/**
 * Guards Session::parsePollReq() against the registry's schemas. nic.it changed
 * dnsErrorMsgData, nothing was watching, and 330 messages parsed as 'unknown'
 * for years -- an unhandled type does not throw, it loses the domain silently.
 */
final class SessionPollCoverageTest extends TestCase
{
    private const SCHEMAS = [
        'extdom' => 'extdom-2.0.xsd',
        'extepp' => 'extepp-2.0.xsd',
    ];

    /**
     * Top-level element declarations in a schema.
     *
     * @return string[] element names, sorted
     */
    private static function declaredElements(string $file): array {
        $dom = new \DOMDocument();
        $dom->load(__DIR__ . '/../../xsd/' . $file);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

        $names = [];
        foreach ($xpath->query('/xs:schema/xs:element/@name') as $attr) {
            $names[] = $attr->nodeValue;
        }
        sort($names);
        return $names;
    }

    /**
     * Which declared elements are poll messages -- '...MsgData' or '...Reminder'
     * upstream, plus remappedIdnData, which carries no suffix. The convention is
     * not trusted alone: the test below fails on any change to the declared set.
     *
     * @return string[]
     */
    private static function pollMessageElements(): array {
        $known = ['remappedIdnData'];

        $elements = [];
        foreach (self::SCHEMAS as $file) {
            foreach (self::declaredElements($file) as $name) {
                if (str_ends_with($name, 'MsgData') || str_ends_with($name, 'Reminder') || in_array($name, $known, true)) {
                    $elements[] = $name;
                }
            }
        }
        sort($elements);
        return $elements;
    }

    /**
     * Every poll message the registry declares must have a branch.
     */
    public function testEveryDeclaredPollMessageIsHandled(): void {
        $declared = self::pollMessageElements();
        $handled = Session::POLL_MESSAGE_ELEMENTS;

        $missing = array_values(array_diff($declared, $handled));

        $this->assertSame(
            [],
            $missing,
            "the registry declares poll message types that parsePollReq() does not handle:\n  - "
                . implode("\n  - ", $missing)
                . "\n\nAn unhandled type does not fail loudly -- it parses as 'unknown' with an"
                . "\nempty domain, so nothing downstream can act on it. Add a branch in"
                . "\nSession::parsePollReq() and an entry in Session::POLL_MESSAGE_ELEMENTS."
        );
    }

    /**
     * And nothing may claim to be handled that the registry does not declare:
     * a stale entry is a branch that can never fire, which is exactly as
     * misleading as a missing one.
     */
    public function testNoHandledTypeIsUnknownToTheSchemas(): void {
        $stale = array_values(array_diff(Session::POLL_MESSAGE_ELEMENTS, self::pollMessageElements()));

        $this->assertSame(
            [],
            $stale,
            "POLL_MESSAGE_ELEMENTS lists types no schema in xsd/ declares:\n  - " . implode("\n  - ", $stale)
        );
    }

    /**
     * A snapshot of every top-level element the extension schemas declare, for
     * the types the convention cannot recognise: a revised schema fails this with
     * its exact additions. Innocent updates fail it too -- the review is cheap.
     *
     * @return array<string, string[]>
     */
    public static function declaredElementSnapshot(): array {
        return [
            'extdom-2.0.xsd' => [
                'chgStatusMsgData', 'delayedDebitAndRefundMsgData', 'dlgMsgData',
                'dnsErrorMsgData', 'dnsWarningMsgData', 'infContacts', 'infContactsData',
                'infData', 'infNsToValidateData', 'refundRenewsForBulkTransferMsgData',
                'remappedIdnData', 'simpleMsgData', 'trade',
            ],
            'extepp-2.0.xsd' => [
                'creditMsgData', 'passwdReminder', 'reasonCode', 'wrongNamespaceReminder', 'wrongValue',
            ],
        ];
    }

    public function testDeclaredExtensionElementsAreUnchanged(): void {
        foreach (self::declaredElementSnapshot() as $file => $expected) {
            $actual = self::declaredElements($file);

            $this->assertSame(
                $expected,
                $actual,
                "the top-level element declarations in xsd/{$file} have changed.\n"
                    . "Added: " . implode(', ', array_diff($actual, $expected)) . "\n"
                    . "Removed: " . implode(', ', array_diff($expected, $actual)) . "\n\n"
                    . "Decide for each addition whether it is a poll message; if it is, add a\n"
                    . "branch in Session::parsePollReq() and an entry in\n"
                    . "Session::POLL_MESSAGE_ELEMENTS. Then update this snapshot."
            );
        }
    }
}
