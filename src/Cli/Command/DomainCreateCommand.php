<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Register domains, or request a transfer for any already held elsewhere.
 *
 * Which of the two happens is the registry's answer, not the caller's choice:
 * the domain is checked first, and a name somebody else holds becomes a
 * transfer request rather than an error. Shares that decision with
 * POST /v1/domains through DomainService.
 */
final class DomainCreateCommand extends Command
{
    public function describe(): string {
        return 'register domains, or request a transfer if already held';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='       => "read domains from this file; either one name per line, or ';'-separated rows of domain;registrant;tech[:tech...];ns[:ns...]",
            'registrant=' => 'registrant contact handle (required unless every --file row supplies its own)',
            'admin='      => 'administrative contact handle',
            'tech='       => 'technical contact handles, colon-separated (up to 6)',
            'ns='         => 'nameservers, colon-separated (up to 6)',
            'authinfo='   => 'authinfo code (generated when omitted)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $rows = $this->rows();
        if ($rows === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        foreach ($rows as $row) {
            if ($row['registrant'] === '') {
                throw new UsageError("no registrant for '{$row['domain']}': pass --registrant, or give one in the file row");
            }
        }

        if ( ! $this->confirm('Register or transfer ' . count($rows) . ' domain(s)? This is a chargeable operation.')) {
            $this->line('nothing done');
            return 0;
        }

        $userId = $this->userId();
        $dryRun = $this->isDryRun();
        $failures = 0;

        $this->withSession(function ($nic) use ($rows, $userId, $dryRun, &$failures) {
            foreach ($rows as $row) {
                // a dry run must not write the local row, so persistence is
                // switched off rather than the result being discarded after
                $result = DomainService::createOrTransfer($nic, $row, $userId, ! $dryRun);

                if ( ! $result['ok']) {
                    $failures++;
                    $this->warn("{$row['domain']}: " . $result['error']);
                    continue;
                }

                $domain = $result['domain'];
                $this->record(
                    sprintf('%-40s %s (authinfo %s)', $row['domain'], $result['action'], $domain->get('authinfo')),
                    [
                        'domain'   => $domain->get('domain'),
                        'action'   => $result['action'],
                        'authinfo' => $domain->get('authinfo'),
                    ]
                );
            }
        });

        return $failures > 0 ? DOMAIN_CREATE_FAILED : 0;
    }

    /**
     * One parameter set per domain.
     *
     * A --file may carry bare names, which take the contacts and nameservers
     * from the options, or ';'-separated rows giving their own -- the two
     * own -- a bulk registration with per-domain nameservers has no other
     * reasonable shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        $defaults = [
            'registrant' => (string) $this->option('registrant', ''),
            'admin'      => (string) $this->option('admin', ''),
            'tech'       => $this->split((string) $this->option('tech', '')),
            'ns'         => $this->split((string) $this->option('ns', '')),
        ];
        if ($this->hasOption('authinfo')) {
            $defaults['authinfo'] = (string) $this->option('authinfo');
        }

        $rows = [];
        foreach ($this->names() as $line) {
            if ( ! str_contains($line, ';')) {
                $rows[] = ['domain' => $line] + $defaults;
                continue;
            }

            $fields = array_map('trim', explode(';', $line));
            $rows[] = [
                'domain'     => $fields[0],
                'registrant' => $fields[1] ?? $defaults['registrant'],
                // the old format had no separate admin column: the registrant
                // stood in for it
                'admin'      => $fields[1] ?? $defaults['admin'],
                'tech'       => isset($fields[2]) ? $this->split($fields[2]) : $defaults['tech'],
                'ns'         => isset($fields[3]) ? $this->split($fields[3]) : $defaults['ns'],
            ] + $defaults;
        }
        return $rows;
    }

    /**
     * @return string[] the registry accepts at most six of either
     */
    private function split(string $value): array {
        return array_slice(array_values(array_filter(array_map('trim', explode(':', $value)))), 0, 6);
    }
}
