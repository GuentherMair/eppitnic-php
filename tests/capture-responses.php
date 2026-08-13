<?php

/**
 * Development tool: capture real registry responses from the local database
 * into tests/fixtures/responses/, anonymised.
 *
 *     php tests/capture-responses.php [--dry-run]
 *
 * Why this exists
 * ---------------
 * The response parsers (Domain::fetch(), Contact::fetch(),
 * Session::parsePollReq() and friends) are the half of this codebase that
 * cannot be checked by generating XML and validating it -- they are only as
 * correct as the documents they are fed. Hand-written samples prove the
 * parser handles what its author imagined; these prove it handles what
 * nic.it actually sends.
 *
 * Why the anonymisation is not optional
 * -------------------------------------
 * `transactions`/`responses`/`msgqueue` hold live traffic: registrant names,
 * postal addresses, phone numbers, e-mail addresses, and authInfo codes --
 * the credential that authorises transferring a domain away. Fixtures are
 * committed to the repository, so anything not scrubbed here is published.
 *
 * Scrubbing is done on the parsed DOM rather than with regular expressions:
 * a regex over XML mangles what it does not understand and silently misses
 * what it does not match, and "silently missed" here means a customer's
 * address in a public git history.
 *
 * As a backstop, every generated fixture is checked against a denylist built
 * from the contacts/domains tables themselves. If any real value survives,
 * nothing is written and the script fails loudly.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Eppitnic\Config;
use RedBeanPHP\R;

$dryRun = in_array('--dry-run', $argv, true);
$outDir = __DIR__ . '/fixtures/responses';

Config::init();

// ---------------------------------------------------------------------------
// anonymisation
// ---------------------------------------------------------------------------

/**
 * Element local names whose text content is replaced wholesale. Keyed by
 * local name because the same element appears under several namespaces
 * (contact:name and domain:name mean different things but both identify).
 */
const SCRUB_TEXT = [
    // postal identity
    'name'   => null,   // context-dependent, see scrubElement()
    'org'    => 'Rossi & Figli S.r.l.',
    'street' => 'Via Roma 1',
    'city'   => 'Bolzano',
    'sp'     => 'BZ',
    'pc'     => '39100',
    'voice'  => '+39.0471000000',
    'fax'    => '+39.0471000001',
    'email'  => 'mario.rossi@example.it',
    // credentials and identifiers
    'pw'         => 'AUTHINFO12345678',
    'clID'       => 'TEST-REG',
    'crID'       => 'TEST-REG',
    'upID'       => 'TEST-REG',
    'reID'       => 'TEST-REG',
    'acID'       => 'OTHER-REG',
    'svTRID'     => 'TEST-SVTRID',
    'clTRID'     => 'TEST-0000000000-00000',
    // registry-specific
    'regCode'    => '01234567890',
    'schoolCode' => 'SC12345',
    'credit'     => '1234.56',
    'digest'     => '2BB183AF5F22588179A53B0A98631FAD1A292118',
];

/** stable pseudonyms, so the same real value maps to the same fake one everywhere */
$handleMap = [];
$domainMap = [];
$domainMapValues = [];

function fakeHandle(string $real): string {
    global $handleMap;
    if ( ! isset($handleMap[$real])) {
        $handleMap[$real] = sprintf('TESTHANDLE%06d', count($handleMap) + 1);
    }
    return $handleMap[$real];
}

/**
 * Map a real domain to a fake one, preserving the shape that matters to the
 * parsers: the .it suffix, and whether it is an IDN (xn--) label.
 */
function fakeDomain(string $real): string {
    global $domainMap, $domainMapValues;
    $real = strtolower(trim($real));
    if ($real === '') {
        return $real;
    }
    // Idempotent: the generic sweep runs after the structured pass and will
    // encounter names this function already replaced. Without this, a
    // second pass would map example-3.it to example-9.it and the same
    // nameserver would appear under two different names in one document.
    if (isset($domainMapValues[$real])) {
        return $real;
    }
    if ( ! isset($domainMap[$real])) {
        $n = count($domainMap) + 1;
        $idn = str_starts_with($real, 'xn--');
        $trailingDot = str_ends_with($real, '.');
        $fake = ($idn ? 'xn--example' . $n . '-9db' : 'example-' . $n) . '.it' . ($trailingDot ? '.' : '');
        $domainMap[$real] = $fake;
        $domainMapValues[$fake] = true;
        $domainMapValues[rtrim($fake, '.')] = true;
    }
    return $domainMap[$real];
}

/**
 * Final catch-all over every text node and attribute value.
 *
 * The structured pass above knows the elements that matter and gives them
 * stable, readable substitutes. It cannot know the rest: these documents carry
 * an open-ended set of DNS diagnostic elements (dnsreport, destination,
 * queryFor, detail, ...) whose text is free-form and full of hostnames and
 * addresses. Enumerating them one by one is a losing game -- each capture
 * turns up another -- so anything that still *looks* like a hostname or an IP
 * after the structured pass is replaced here regardless of where it sits.
 */
function sweepFreeText(\DOMDocument $dom): void {
    $hostname = '/\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)*\.[a-z]{2,}\.?/i';
    $ipv4 = '/\b\d{1,3}(?:\.\d{1,3}){3}\b/';
    $ipv6 = '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i';

    foreach ((new \DOMXPath($dom))->query('//text() | //@*') as $node) {
        // namespace declarations and schema locations are structure, not data;
        // rewriting them would break the document
        if ($node instanceof \DOMAttr
            && (str_starts_with($node->nodeName, 'xmlns') || $node->localName === 'schemaLocation')) {
            continue;
        }

        $value = $node->nodeValue ?? '';
        if ($value === '' || str_contains($value, '://') || str_starts_with(trim($value), 'urn:')) {
            continue;
        }

        $updated = preg_replace($ipv4, '192.0.2.1', $value);
        $updated = preg_replace($ipv6, '2001:db8::1', $updated);
        $updated = preg_replace_callback($hostname, fn($m) => fakeDomain($m[0]), $updated);

        if ($updated !== $value) {
            $node->nodeValue = '';
            if ($node instanceof \DOMAttr) {
                $node->value = htmlspecialchars($updated, ENT_XML1);
            } else {
                $node->nodeValue = $updated;
            }
        }
    }
}

/**
 * @return bool whether $value looks like a domain name rather than a person
 */
function looksLikeDomain(string $value): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}\.?$/i', trim($value));
}

function scrubElement(\DOMElement $el): void {
    $local = $el->localName;

    // attributes that carry identity
    if ($el->hasAttribute('name') && looksLikeDomain($el->getAttribute('name'))) {
        $el->setAttribute('name', fakeDomain($el->getAttribute('name')));
    }
    if ($el->hasAttribute('ip')) {
        // hostAddr values live in text, handled below; the attribute is v4/v6
    }

    // text-bearing elements
    $text = trim($el->textContent);
    $hasElementChildren = false;
    foreach ($el->childNodes as $child) {
        if ($child instanceof \DOMElement) {
            $hasElementChildren = true;
            break;
        }
    }

    if ( ! $hasElementChildren && $text !== '') {
        $replacement = null;

        if (str_contains($text, "\n")) {
            // Multi-line free text in these documents is a raw DNS transcript
            // (extdom:dnsreport carries a whole zone dump in CDATA: SOA, MX,
            // SPF ranges, the hostmaster address). No parser reads a word of
            // it -- Session::parsePollReq() works off the surrounding elements
            // and attributes -- so it is pure risk with no test value.
            // Replaced wholesale rather than pattern-scrubbed: an unstructured
            // blob is exactly where a targeted scrubber misses something.
            $replacement = '[dns transcript removed]';
        } elseif ($local === 'name') {
            // domain:name / extdom:name carry a domain; contact:name a person
            $replacement = looksLikeDomain($text) ? fakeDomain($text) : 'Mario Rossi';
        } elseif (in_array($local, ['queryFor', 'responseId'], true)) {
            $replacement = $local === 'responseId'
                ? '00000000-0000-4000-8000-000000000000'
                : fakeDomain($text);
        } elseif ($local === 'domain' && looksLikeDomain($text)) {
            // extdom-2.0 dnsErrorMsgData/dnsWarningMsgData name the domain here
            $replacement = fakeDomain($text);
        } elseif ($local === 'hostName') {
            $replacement = 'ns1.' . fakeDomain($text);
        } elseif ($local === 'hostAddr' || $local === 'address') {
            // 'address' is extdom-2.0's nameserver IP -- this is the registrar's
            // own DNS infrastructure, not something to publish in fixtures
            $replacement = str_contains($text, ':') ? '2001:db8::1' : '192.0.2.1';
        } elseif ($local === 'validationId') {
            $replacement = '00000000-0000-4000-8000-000000000000';
        } elseif (in_array($local, ['id', 'roid', 'registrant', 'contact', 'newRegistrant', 'oldRegistrant'], true)) {
            $replacement = fakeHandle($text);
        } elseif (array_key_exists($local, SCRUB_TEXT) && SCRUB_TEXT[$local] !== null) {
            $replacement = SCRUB_TEXT[$local];
        } elseif ($local === 'msg') {
            // poll message titles quote the domain they are about
            $replacement = preg_replace_callback(
                '/\b([a-z0-9][a-z0-9-]*\.it)\b\.?/i',
                fn($m) => fakeDomain($m[1]),
                $text
            );
        }

        if ($replacement !== null && $replacement !== $text) {
            while ($el->firstChild) {
                $el->removeChild($el->firstChild);
            }
            $el->appendChild($el->ownerDocument->createTextNode($replacement));
        }
    }

    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof \DOMElement) {
            scrubElement($child);
        }
    }
}

/**
 * Unwrap a stored response body.
 *
 * Historical rows (written by the 6.x codebase) are wrapped in a
 * "__SERIALIZED:" + base64(serialize($string)) envelope; rows written by the
 * current AbstractObject::ExecuteQuery() are the plain string. A production
 * database contains both, so both are handled here rather than assuming one.
 *
 * @param string $stored the raw column value
 * @return string|null the response body, or null if the envelope is unreadable
 */
function unwrap(string $stored): ?string {
    if ( ! str_starts_with($stored, '__SERIALIZED:')) {
        return $stored;
    }

    $decoded = base64_decode(substr($stored, strlen('__SERIALIZED:')), true);
    if ($decoded === false) {
        return null;
    }

    $value = @unserialize($decoded);
    return is_string($value) ? $value : null;
}

function scrub(string $xml): ?string {
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if ( ! @$dom->loadXML($xml)) {
        return null;
    }
    if ($dom->documentElement === null) {
        return null;
    }
    scrubElement($dom->documentElement);
    sweepFreeText($dom);
    return $dom->saveXML();
}

// ---------------------------------------------------------------------------
// denylist backstop
// ---------------------------------------------------------------------------

/**
 * Every real value that must never appear in a fixture, taken from the tables
 * themselves rather than guessed at.
 *
 * @return string[]
 */
function buildDenylist(): array {
    $values = [];
    foreach (['name', 'org', 'email', 'voice', 'fax', 'street', 'city', 'authinfo', 'handle', 'regcode'] as $col) {
        foreach (R::getCol("SELECT DISTINCT `{$col}` FROM contacts WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''") as $v) {
            $values[] = (string) $v;
        }
    }
    foreach (['domain', 'authinfo'] as $col) {
        foreach (R::getCol("SELECT DISTINCT `{$col}` FROM domains WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''") as $v) {
            $values[] = (string) $v;
        }
    }

    // Short or generic values would match by coincidence ('IT', 'ok', a bare
    // number) and make the check useless through false positives.
    $values = array_filter($values, fn($v) => strlen(trim($v)) >= 6);

    // The substitutes this script writes are themselves plausible real values
    // -- 'Bolzano' is a real city in this dataset, and it is also what every
    // city is replaced *with*. Leaving them on the denylist would flag every
    // correctly-scrubbed fixture. Removing them is safe precisely because the
    // substitution is unconditional: the output value is the constant whatever
    // the input was, so it carries nothing about the original.
    $substitutes = array_map('strval', array_filter(array_values(SCRUB_TEXT)));
    $substitutes[] = 'Mario Rossi';

    return array_values(array_diff(array_unique($values), $substitutes));
}

/**
 * Every text node and attribute value in a document -- what a reader actually
 * learns from it.
 *
 * The check has to run over these rather than over the raw markup, because
 * markup contains strings that are not data: the XML declaration's
 * standalone="no" contains "andalo", which is also a real municipality in this
 * dataset, so a naive substring scan of the serialized document reports a leak
 * in every single response.
 *
 * @return string[]
 */
function documentValues(string $xml): array {
    $dom = new \DOMDocument();
    if ( ! @$dom->loadXML($xml)) {
        return [$xml]; // unparseable: fall back to checking everything
    }

    $values = [];
    foreach ((new \DOMXPath($dom))->query('//text() | //@*') as $node) {
        $value = trim($node->nodeValue ?? '');
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return $values;
}

/**
 * @return string[] denylisted values found in $xml
 */
function leaks(string $xml, array $denylist): array {
    $found = [];
    $haystack = array_map('mb_strtolower', documentValues($xml));

    foreach ($denylist as $value) {
        $needle = mb_strtolower(trim($value));
        foreach ($haystack as $candidate) {
            if (str_contains($candidate, $needle)) {
                $found[] = $value;
                continue 2;
            }
        }
    }
    return $found;
}

// ---------------------------------------------------------------------------
// selection
// ---------------------------------------------------------------------------

$captures = [];

// one successful and one failed response per command type
foreach (R::getAll("SELECT DISTINCT cl_trtype FROM transactions WHERE cl_trtype IS NOT NULL") as $row) {
    $type = $row['cl_trtype'];
    foreach ([['ok', "r.sv_code LIKE '1%'"], ['error', "r.sv_code LIKE '2%'"]] as [$label, $cond]) {
        $hit = R::getRow("
            SELECT r.sv_httpdata, r.sv_code
            FROM transactions t JOIN responses r ON r.cl_trid = t.cl_trid
            WHERE t.cl_trtype = :type AND {$cond} AND r.sv_httpdata IS NOT NULL AND r.sv_httpdata <> ''
            ORDER BY r.id DESC LIMIT 1
        ", [':type' => $type]);
        if ( ! empty($hit)) {
            $captures["{$type}-{$label}"] = $hit['sv_httpdata'];
        }
    }
}

/**
 * Poll responses, one per *document shape*.
 *
 * Deliberately keyed on what the document actually contains -- the qualified
 * name of the element under <extension>, or the transfer status under
 * <resData> -- and not on messages.type. messages.type is the output of
 * Session::parsePollReq(), so keying fixtures on it means the fixture set
 * inherits whatever that parser gets wrong: every message it fails to
 * recognise collapses into a single "unknown" bucket, and the shapes it is
 * failing on become invisible precisely because it is failing on them.
 *
 * Keying on the document instead, the same corpus yields a separate fixture
 * for each real message shape, including the ones the parser does not
 * currently handle.
 *
 * The namespace is part of the key: nic.it kept the element name
 * dnsErrorMsgData across extdom-1.0 and extdom-2.0 while changing its
 * structure completely, and both still occur in the queue.
 */
$seen = [];
foreach (R::getAll("SELECT id, cl_trid, sv_httpdata FROM msgqueue WHERE sv_httpdata IS NOT NULL AND sv_httpdata <> '' ORDER BY id DESC") as $row) {
    $body = unwrap($row['sv_httpdata']);
    if ($body === null) {
        continue;
    }

    $dom = new \DOMDocument();
    if ( ! @$dom->loadXML($body)) {
        continue;
    }
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');

    $key = null;
    foreach ($xpath->query('/e:epp/e:response/e:extension/*') as $node) {
        $shortNs = preg_replace('#^.*/#', '', $node->namespaceURI ?? '');
        $key = 'poll-' . $node->localName . ($shortNs !== '' ? '-' . $shortNs : '');
        break;
    }
    if ($key === null) {
        // transfer notifications carry no extension; they are resData
        foreach ($xpath->query('/e:epp/e:response/e:resData/*/*[local-name()="trStatus"]') as $node) {
            $key = 'poll-transfer-' . $node->textContent;
            break;
        }
    }
    if ($key === null || isset($seen[$key])) {
        continue;
    }

    $seen[$key] = true;
    $captures[$key] = $row['sv_httpdata'];
}

// ---------------------------------------------------------------------------
// write
// ---------------------------------------------------------------------------

$denylist = buildDenylist();
fwrite(STDOUT, count($denylist) . " real values on the denylist\n");
fwrite(STDOUT, count($captures) . " candidate responses\n\n");

$clean = [];
$failed = [];

foreach ($captures as $name => $raw) {
    $body = unwrap($raw);
    if ($body === null) {
        $failed[$name] = 'unreadable storage envelope';
        continue;
    }
    $scrubbed = scrub($body);
    if ($scrubbed === null) {
        $failed[$name] = 'not well-formed XML';
        continue;
    }
    $found = leaks($scrubbed, $denylist);
    if ( ! empty($found)) {
        $failed[$name] = 'LEAK: ' . implode(', ', array_slice($found, 0, 5));
        continue;
    }
    $clean[$name] = $scrubbed;
}

foreach ($failed as $name => $why) {
    fwrite(STDOUT, sprintf("  SKIP  %-34s %s\n", $name, $why));
}

// --show=<name> prints one scrubbed capture, leaking or not, so a rejected
// fixture can be inspected without having to write it to disk first
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--show=')) {
        $wanted = substr($arg, strlen('--show='));
        if ( ! isset($captures[$wanted])) {
            fwrite(STDERR, "no such capture: {$wanted}\n");
            exit(1);
        }
        $body = unwrap($captures[$wanted]);
        fwrite(STDOUT, "\n=== {$wanted} (scrubbed) ===\n" . (scrub($body ?? '') ?? '[unscrubbable]') . "\n");
        exit(0);
    }
}

if ( ! empty($failed)) {
    fwrite(STDOUT, "\n" . count($failed) . " response(s) could not be captured safely (see above).\n");
}

if ($dryRun) {
    fwrite(STDOUT, "\n--dry-run: would write " . count($clean) . " fixtures to {$outDir}\n");
    foreach (array_keys($clean) as $name) {
        fwrite(STDOUT, "  {$name}.xml\n");
    }
    exit(0);
}

if ( ! is_dir($outDir)) {
    mkdir($outDir, 0o755, true);
}
foreach ($clean as $name => $xml) {
    file_put_contents("{$outDir}/{$name}.xml", $xml);
}

fwrite(STDOUT, "\nwrote " . count($clean) . " fixtures to {$outDir}\n");
