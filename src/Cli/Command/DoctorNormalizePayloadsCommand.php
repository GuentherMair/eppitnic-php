<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Persistence\StoredPayload;
use RedBeanPHP\R;

/**
 * Rewrite 6.x's `__SERIALIZED:` columns as the plain bodies they hold.
 * Optional, since StoredPayload::decode() reads either shape; undecodable rows
 * are counted and left alone, a damaged envelope being the only copy of what it
 * holds.
 */
final class DoctorNormalizePayloadsCommand extends Command
{
    /**
     * Every column the 6.x codebase wrapped. Some will be empty in any given
     * installation -- which ones depends on how far back its history goes.
     *
     * @var array<int, array{0: string, 1: string}> table, column
     */
    private const WRAPPED_COLUMNS = [
        ['transactions', 'cl_trdata'],
        ['responses',    'sv_httpdata'],
        ['responses',    'sv_httpheaders'],
        ['responses',    'extvaluereason'],
        ['msgqueue',     'sv_httpdata'],
        ['msgqueue',     'sv_httpheaders'],
    ];

    /** rows read per round trip; these columns hold whole EPP documents */
    private const BATCH = 500;

    /**
     * The marker as a LIKE pattern: `_` is a wildcard, so the leading
     * underscores are escaped with `!` rather than a backslash, which would
     * have to survive both PHP and SQL quoting.
     */
    private const MARKER_LIKE = '!_!_SERIALIZED:%';
    private const MARKER_ESCAPE = "ESCAPE '!'";

    public function describe(): string {
        return 'rewrite legacy __SERIALIZED: columns as plain bodies';
    }

    public function options(): array {
        return [
            'dry-run' => 'report what would change, without writing',
            'yes'     => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        $this->database();

        $counts = [];
        $total = 0;
        foreach (self::WRAPPED_COLUMNS as [$table, $column]) {
            $n = (int) R::getCell(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE :marker " . self::MARKER_ESCAPE,
                [':marker' => self::MARKER_LIKE]
            );
            if ($n > 0) {
                $counts["{$table}.{$column}"] = $n;
                $total += $n;
            }
        }

        if ($total === 0) {
            $this->line('no enveloped rows');
            return 0;
        }

        foreach ($counts as $where => $n) {
            $this->line(sprintf('%-32s %d row(s) enveloped', $where, $n));
        }
        $this->line('');

        if ($this->hasOption('dry-run')) {
            $this->line("{$total} row(s) would be rewritten");
            return 0;
        }

        if ( ! $this->confirm("Rewrite {$total} row(s)? The envelope is not recoverable afterwards.")) {
            $this->line('nothing written');
            return 0;
        }

        $rewritten = 0;
        $unreadable = 0;

        foreach (self::WRAPPED_COLUMNS as [$table, $column]) {
            if ( ! isset($counts["{$table}.{$column}"])) {
                continue;
            }
            [$done, $bad] = $this->normalize($table, $column);
            $rewritten += $done;
            $unreadable += $bad;
        }

        $this->line(sprintf('%d row(s) rewritten', $rewritten));
        if ($unreadable > 0) {
            // left as they are: a damaged envelope is still the only copy of
            // whatever it holds, and StoredPayload::decode() keeps reporting
            // it as unreadable rather than as an empty body
            $this->warn("{$unreadable} row(s) could not be decoded and were left alone");
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: int} rewritten, undecodable
     */
    private function normalize(string $table, string $column): array {
        $rewritten = 0;
        $unreadable = 0;
        $afterId = 0;

        // Keyed off the id rather than a plain LIMIT: each pass removes rows
        // from the set the WHERE clause matches, so an offset would walk past
        // the ones that shifted down into its place.
        while (true) {
            $rows = R::getAll(
                "SELECT `id`, `{$column}` AS payload FROM `{$table}`
                 WHERE `{$column}` LIKE :marker " . self::MARKER_ESCAPE . " AND `id` > :after
                 ORDER BY `id` LIMIT " . self::BATCH,
                [':marker' => self::MARKER_LIKE, ':after' => $afterId]
            );
            if ($rows === []) {
                return [$rewritten, $unreadable];
            }

            foreach ($rows as $row) {
                $afterId = (int) $row['id'];

                // the LIKE narrowed it; this is the authority on the shape
                if ( ! StoredPayload::isWrapped((string) $row['payload'])) {
                    continue;
                }

                $plain = StoredPayload::decode((string) $row['payload']);
                if ($plain === null) {
                    $unreadable++;
                    continue;
                }

                R::exec(
                    "UPDATE `{$table}` SET `{$column}` = :payload WHERE `id` = :id",
                    [':payload' => $plain, ':id' => $row['id']]
                );
                $rewritten++;
            }
        }
    }
}
