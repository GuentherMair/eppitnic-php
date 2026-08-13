<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;

/**
 * Reach the registry and report what it says about itself.
 *
 * Deliberately does not log in: this is the command to run when something is
 * wrong and the question is whether the problem is the connection, the
 * credentials, or something further in.
 */
final class SessionHelloCommand extends Command
{
    public function describe(): string {
        return 'contact the registry and show its greeting (no login)';
    }

    public function run(): int {
        $nic = new Client();
        $nic->debug = $this->isVerbose();
        $session = new Session($nic);

        if ( ! $session->hello()) {
            $this->warn('No greeting from ' . $nic->EPPCfg->server
                . ' (HTTP ' . ($session->result?->code ?? '-') . ')');
            return HELLO_FAILED;
        }

        $greeting = $session->xmlResult->greeting;
        $record = [
            'server'     => (string) $nic->EPPCfg->server,
            'sv_id'      => (string) $greeting->svID,
            'sv_date'    => (string) $greeting->svDate,
            'versions'   => array_map('strval', iterator_to_array($greeting->svcMenu->version, false)),
            'languages'  => array_map('strval', iterator_to_array($greeting->svcMenu->lang, false)),
            'objects'    => array_map('strval', iterator_to_array($greeting->svcMenu->objURI, false)),
            'extensions' => isset($greeting->svcMenu->svcExtension)
                ? array_map('strval', iterator_to_array($greeting->svcMenu->svcExtension->extURI, false))
                : [],
        ];

        $text = $record['sv_id'] . ' at ' . $record['server'] . "\n"
              . sprintf("  %-12s %s\n", 'time', $record['sv_date'])
              . sprintf("  %-12s %s\n", 'versions', implode(', ', $record['versions']))
              . sprintf("  %-12s %s", 'languages', implode(', ', $record['languages']));
        foreach ($record['objects'] as $uri) {
            $text .= sprintf("\n  %-12s %s", 'object', $uri);
        }
        foreach ($record['extensions'] as $uri) {
            $text .= sprintf("\n  %-12s %s", 'extension', $uri);
        }

        $this->record($text, $record);
        return 0;
    }
}
