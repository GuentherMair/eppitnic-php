<?php

namespace Net\EPP\Cli;

use Net\EPP\Helpers;
use Net\EPP\IT\Domain;
use RedBeanPHP\R;

/**
 * Domains from the local database or from the registry, as CSV (the default),
 * JSON Lines or one JSON document.
 *
 * Replaces CLI/domain-ExportLocalToCsv.php (--source=local, the default) and
 * CLI/domain-ExportDetailsToCsv.php (--source=registry), which produced the
 * same shape of file from different places and each wrote out the CSV quoting
 * by hand.
 */
final class DomainExportCommand extends Command
{
    private const COLUMNS = [
        'Active'            => 'active',
        'Domain'            => 'domain',
        'Auth-Info'         => 'authinfo',
        'Created'           => 'cr_date',
        'Expires'           => 'ex_date',
        'Registrant Handle' => 'handle',
        'Registrant Org'    => 'org',
        'Registrant Name'   => 'name',
        'Registrant Email'  => 'email',
    ];

    public function describe(): string {
        return 'export domains as CSV, JSON Lines or JSON';
    }

    public function arguments(): string {
        return '[<domain>...]';
    }

    public function options(): array {
        return [
            'source='   => "'local' (default) reads the database, 'registry' fetches each domain live",
            'csv'       => 'write CSV (the default for this command)',
            'output='   => 'write to this file instead of standard output',
            'file='     => 'read domain names from this file (--source=registry)',
            'all-users' => 'export every user\'s domains, not just --user\'s',
        ];
    }

    /**
     * An export is a bulk dump, so CSV is what it produces unless asked
     * otherwise. --jsonl is the other format that suits the job; --json works
     * too, but holds every row in memory to emit one array.
     */
    protected function defaultFormat(): string {
        return self::FORMAT_CSV;
    }

    public function run(): int {
        $source = (string) $this->option('source', 'local');
        if ( ! in_array($source, ['local', 'registry'], true)) {
            throw new UsageError("--source must be 'local' or 'registry'");
        }

        if ($this->hasOption('csv') && $this->format() !== self::FORMAT_CSV) {
            throw new UsageError('--csv cannot be combined with --json or --jsonl');
        }

        $rows = $source === 'local' ? $this->fromDatabase() : $this->fromRegistry();

        // normalised to the documented column set in either format, so a
        // consumer sees the same fields whichever it asks for
        $records = array_map(
            fn($row) => array_combine(
                array_values(self::COLUMNS),
                array_map(fn($field) => $row[$field] ?? '', array_values(self::COLUMNS))
            ),
            $rows
        );

        $body = $this->render($records);

        if ($output = $this->option('output')) {
            if (file_put_contents($output, $body) === false) {
                $this->warn("unable to write to '{$output}'");
                return OUTPUT_ERROR;
            }
            $this->line(count($records) . " domain(s) written to {$output}");
            return 0;
        }

        echo $body;
        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return string the whole export, in the requested format
     */
    private function render(array $records): string {
        switch ($this->format()) {
            case self::FORMAT_JSON:
                return json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            case self::FORMAT_JSONL:
                $out = '';
                foreach ($records as $record) {
                    $out .= json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
                }
                return $out;

            default:
                $out = Helpers::rowToCSV(array_keys(self::COLUMNS), ';');
                foreach ($records as $record) {
                    $out .= Helpers::rowToCSV(array_values($record), ';');
                }
                return $out;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromDatabase(): array {
        $this->database();

        $where = ['d.registrant = c.handle'];
        $params = [];
        if ( ! $this->hasOption('all-users')) {
            $where[] = 'd.user_id = :user_id';
            $params[':user_id'] = $this->userId();
        }
        if ($names = $this->names()) {
            $in = [];
            foreach (array_values($names) as $i => $name) {
                $in[] = ":d{$i}";
                $params[":d{$i}"] = $name;
            }
            $where[] = 'd.domain IN (' . implode(', ', $in) . ')';
        }

        return R::getAll('
            SELECT d.active, d.domain, d.authinfo, d.cr_date, d.ex_date,
                   c.handle, c.org, c.name, c.email
            FROM contacts c, domains d
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.domain ASC', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromRegistry(): array {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('--source=registry needs domain names, or --file=PATH');
        }

        return $this->withSession(function ($nic) use ($names) {
            $rows = [];
            foreach ($names as $name) {
                $domain = new Domain($nic);
                if ( ! $domain->fetch($name)) {
                    $this->warn("{$name}: " . ($domain->getError() ?: 'not found'));
                    continue;
                }

                // the registrant's postal details need a second lookup; the
                // registry does not return them with the domain unless
                // infContacts is asked for, which needs the authinfo we do not
                // have for every domain here
                $contact = new \Net\EPP\IT\Contact($nic);
                $known = $contact->fetch($domain->get('registrant'));

                $rows[] = [
                    'active'   => 1,
                    'domain'   => $domain->get('domain'),
                    'authinfo' => $domain->get('authinfo'),
                    'cr_date'  => $domain->get('crDate'),
                    'ex_date'  => $domain->get('exDate'),
                    'handle'   => $domain->get('registrant'),
                    'org'      => $known ? $contact->get('org') : '',
                    'name'     => $known ? $contact->get('name') : '',
                    'email'    => $known ? $contact->get('email') : '',
                ];
            }
            return $rows;
        });
    }
}
