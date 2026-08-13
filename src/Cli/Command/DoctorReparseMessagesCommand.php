<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;
use Eppitnic\Persistence\StoredPayload;
use RedBeanPHP\R;

/**
 * Re-derive `type` and `domain` on stored poll messages from the raw responses
 * still held in `msgqueue`.
 *
 * Messages carry whatever the parser made of them at the time they were
 * polled, so a release that teaches the parser a message shape it did not know
 * -- as 7.0 did for the extdom-2.0 DNS messages -- leaves the older rows
 * saying 'unknown' with no domain.
 *
 * Rewrites those two columns and nothing else. In particular it fires no side
 * effects: no reminder rows, no DNS-sync events for validation failures that
 * are years old.
 */
final class DoctorReparseMessagesCommand extends Command
{
    public function describe(): string {
        return 're-derive type/domain on stored poll messages';
    }

    public function options(): array {
        return [
            'all'     => 'reconsider every message, not only those typed unknown',
            'dry-run' => 'report what would change, without writing',
        ];
    }

    public function run(): int {
        $this->database();

        $where = $this->hasOption('all') ? '1 = 1' : "m.type = 'unknown'";
        $rows = R::getAll("
            SELECT m.id, m.type, m.domain, q.sv_httpdata
            FROM messages m
            JOIN msgqueue q ON q.cl_trid = m.cl_trid
            WHERE {$where} AND q.sv_httpdata IS NOT NULL AND q.sv_httpdata <> ''
            ORDER BY m.id
        ");

        if ($rows === []) {
            $this->line('nothing to re-parse');
            return 0;
        }

        // a client only to give the parser somewhere to live: nothing here
        // contacts the registry, the responses are already on disk
        $session = new Session(new Client());
        $parse = new \ReflectionMethod(Session::class, 'parsePollReq');

        $changed = 0;
        $unreadable = 0;

        foreach ($rows as $row) {
            $body = StoredPayload::decode((string) $row['sv_httpdata']);
            if ($body === null) {
                $unreadable++;
                continue;
            }

            $session->xmlResult = simplexml_load_string($body);
            if ($session->xmlResult === false) {
                $unreadable++;
                continue;
            }

            $parsed = $parse->invoke($session);
            if ($parsed['type'] === $row['type'] && $parsed['domain'] === (string) $row['domain']) {
                continue;
            }

            $this->record(
                sprintf('%-10s %-22s -> %-30s %s', $row['id'], $row['type'], $parsed['type'], $parsed['domain']),
                [
                    'id'   => (int) $row['id'],
                    'from' => ['type' => $row['type'], 'domain' => $row['domain']],
                    'to'   => ['type' => $parsed['type'], 'domain' => $parsed['domain']],
                ]
            );
            $changed++;

            if ( ! $this->hasOption('dry-run')) {
                R::exec("UPDATE messages SET type = :type, domain = :domain WHERE id = :id", [
                    ':type'   => $parsed['type'],
                    ':domain' => $parsed['domain'],
                    ':id'     => $row['id'],
                ]);
            }
        }

        $this->line('');
        $this->line(sprintf('%d of %d message(s) %s', $changed, count($rows),
            $this->hasOption('dry-run') ? 'would change' : 'updated'));
        if ($unreadable > 0) {
            $this->warn("{$unreadable} stored response(s) could not be read");
        }

        return 0;
    }
}
