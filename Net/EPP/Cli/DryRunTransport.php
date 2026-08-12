<?php

namespace Net\EPP\Cli;

use Net\EPP\Transport;

/**
 * A transport that answers everything itself, so `--dry-run` shows exactly
 * what would be sent without a single byte leaving the process.
 *
 * The alternative -- connecting, logging in and stopping short of the one
 * command that changes something -- would still need credentials, still touch
 * the registry, and would differ from a real run in a way that is easy to get
 * subtly wrong. Answering locally means a dry run works offline, works with no
 * credentials configured, and cannot possibly modify anything.
 *
 * What it gives up is registry validation: a request that this prints happily
 * may still be refused when actually sent. Schema validity is what
 * tests/Wire covers; this answers "what would go out".
 *
 * @category    Net
 * @package     Net\EPP\Cli
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DryRunTransport implements Transport
{
    /** @var string[] every request the command tried to send, in order */
    private array $requests = [];

    private const GREETING = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <greeting>
        <svID>dry run (nothing was sent)</svID>
        <svDate>1970-01-01T00:00:00.000+00:00</svDate>
        <svcMenu><version>1.0</version><lang>en</lang></svcMenu>
      </greeting>
    </epp>
    XML;

    private const SUCCESS = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Dry run: not sent</msg></result>
        <trID><svTRID>dry-run</svTRID></trID>
      </response>
    </epp>
    XML;

    /**
     * The commands the caller would have sent, with the session plumbing
     * (hello, login, logout) left out -- those are the same every time and
     * are not what anyone is inspecting.
     *
     * @return string[]
     */
    public function sentRequests(): array {
        return array_values(array_filter(
            $this->requests,
            fn($request) => ! preg_match('#<(hello|login|logout)\b#', $request)
        ));
    }

    public function query(?string $postFields = null): string {
        $request = (string) $postFields;
        $this->requests[] = $request;

        // hello is answered with a greeting; everything else with a bare
        // success, which is enough for the object layer to carry on and
        // generate any further requests the command makes
        return str_contains($request, '<hello') ? self::GREETING : self::SUCCESS;
    }

    public function getHttpStatus(): int {
        return 200;
    }

    public function getHttpHeaders(): string {
        return "HTTP/1.1 200 OK\r\nContent-Type: text/xml; charset=UTF-8\r\n\r\n";
    }

    public function getHttpError(): string {
        return '';
    }
}
