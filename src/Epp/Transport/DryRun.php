<?php

namespace Eppitnic\Epp\Transport;

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
 * @package     Eppitnic\Cli
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DryRun implements Transport
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

        if (str_contains($request, '<hello')) {
            return self::GREETING;
        }

        // A <check> has to be answered with an actual availability, because
        // the caller branches on it: create-or-transfer picks its command from
        // the answer, so a bare success would leave it unable to choose and
        // the preview would show nothing at all. Every name is reported
        // available, which previews the create branch; a transfer preview is
        // what `domain transfer request --dry-run` is for.
        if (preg_match('#<(domain|contact):check\b#', $request, $m)) {
            return $this->availability($request, $m[1]);
        }

        // A <domain:info> is answered with a minimal record, because the
        // commands that read before they write -- `domain status` -- need the
        // read to succeed before they generate the request worth previewing.
        // The values are placeholders; what those commands actually send is
        // built from the arguments, not from this.
        if (preg_match('#<domain:info\b#', $request)) {
            return $this->domainInfo($request);
        }

        // everything else: a bare success, enough for the object layer to
        // carry on and generate any further requests the command makes
        return self::SUCCESS;
    }

    /**
     * A chkData answer reporting every name in the request as available.
     */
    private function availability(string $request, string $prefix): string {
        $element = $prefix === 'domain' ? 'name' : 'id';
        preg_match_all("#<{$prefix}:{$element}>([^<]+)</{$prefix}:{$element}>#", $request, $matches);

        $cds = '';
        foreach ($matches[1] ?? [] as $value) {
            $cds .= "      <{$prefix}:cd><{$prefix}:{$element} avail=\"true\">"
                  . htmlspecialchars($value, ENT_XML1)
                  . "</{$prefix}:{$element}></{$prefix}:cd>\n";
        }

        $uri = "urn:ietf:params:xml:ns:{$prefix}-1.0";
        return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n"
             . "<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\">\n"
             . "  <response>\n"
             . "    <result code=\"1000\"><msg lang=\"en\">Dry run: not sent</msg></result>\n"
             . "    <resData>\n"
             . "      <{$prefix}:chkData xmlns:{$prefix}=\"{$uri}\">\n"
             . $cds
             . "      </{$prefix}:chkData>\n"
             . "    </resData>\n"
             . "    <trID><svTRID>dry-run</svTRID></trID>\n"
             . "  </response>\n"
             . "</epp>\n";
    }

    /**
     * The least domain:infData that Domain::fetch() accepts.
     */
    private function domainInfo(string $request): string {
        preg_match('#<domain:name[^>]*>([^<]+)</domain:name>#', $request, $m);
        $name = htmlspecialchars($m[1] ?? 'example.it', ENT_XML1);

        return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n"
             . "<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\">\n"
             . "  <response>\n"
             . "    <result code=\"1000\"><msg lang=\"en\">Dry run: not sent</msg></result>\n"
             . "    <resData>\n"
             . "      <domain:infData xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\">\n"
             . "        <domain:name>{$name}</domain:name>\n"
             . "        <domain:status s=\"ok\"/>\n"
             . "        <domain:registrant>DRY-RUN-REGISTRANT</domain:registrant>\n"
             . "        <domain:authInfo><domain:pw>DRY-RUN-AUTHINFO</domain:pw></domain:authInfo>\n"
             . "      </domain:infData>\n"
             . "    </resData>\n"
             . "    <trID><svTRID>dry-run</svTRID></trID>\n"
             . "  </response>\n"
             . "</epp>\n";
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
