<?php

namespace Net\EPP\Cli\Command;

use Net\EPP\Cli\Command;
use Net\EPP\Cli\UsageError;
use Net\EPP\Epp\Domain;

/**
 * Change a domain's nameservers, technical contacts, admin contact or authinfo.
 *
 * Registrant changes are not here: they are a distinct EPP command that
 * requires the authinfo to change alongside them, which is what
 * `domain set-registrant` is for.
 */
final class DomainUpdateCommand extends Command
{
    public function describe(): string {
        return 'change nameservers, contacts or authinfo on domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='     => "read rows from this file: 'domain', or 'domain;add-ns[:ns...];remove-ns[:ns...]'",
            'add-ns='   => 'nameservers to add, colon-separated',
            'rem-ns='   => 'nameservers to remove, colon-separated',
            'add-tech=' => 'technical contacts to add, colon-separated',
            'rem-tech=' => 'technical contacts to remove, colon-separated',
            'tech='     => 'the complete technical contact list; anything else is removed',
            'admin='    => 'set the administrative contact',
            'authinfo=' => 'set a new authinfo code',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $rows = $this->rows();
        if ($rows === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $hasChange = fn(array $row) => $row['add_ns'] || $row['rem_ns'] || $row['add_tech']
            || $row['rem_tech'] || $row['tech'] !== null || $row['admin'] !== null || $row['authinfo'] !== null;

        foreach ($rows as $row) {
            if ( ! $hasChange($row)) {
                throw new UsageError("nothing to change for '{$row['domain']}': give at least one option");
            }
        }

        if ( ! $this->confirm('Update ' . count($rows) . ' domain(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $failures = 0;

        $this->withSession(function ($nic) use ($rows, &$failures) {
            foreach ($rows as $row) {
                $domain = new Domain($nic);

                // update() sends the difference against what was fetched, so
                // the read is what makes an add or a removal meaningful
                if ( ! $domain->fetch($row['domain'])) {
                    $failures++;
                    $this->warn("{$row['domain']}: " . ($domain->getError() ?: 'not found'));
                    continue;
                }

                foreach ($row['add_ns'] as $ns) {
                    $domain->addNS($ns);
                }
                foreach ($row['rem_ns'] as $ns) {
                    $domain->remNS($ns);
                }
                foreach ($row['add_tech'] as $tech) {
                    $domain->addTECH($tech);
                }
                foreach ($row['rem_tech'] as $tech) {
                    $domain->remTECH($tech);
                }

                // --tech is a target list, not a delta: whatever the domain
                // has that is not in it goes. This is what makes syncing a set
                // of domains onto one technical contact a single command.
                if ($row['tech'] !== null) {
                    $current = array_keys((array) $domain->get('tech'));
                    foreach (array_diff($current, $row['tech']) as $gone) {
                        $domain->remTECH($gone);
                    }
                    foreach (array_diff($row['tech'], $current) as $added) {
                        $domain->addTECH($added);
                    }
                }

                if ($row['admin'] !== null) {
                    $domain->set('admin', $row['admin']);
                }
                if ($row['authinfo'] !== null) {
                    $domain->set('authinfo', $row['authinfo']);
                }

                if ( ! $domain->hasChanges()) {
                    $this->line("{$row['domain']}: already as requested");
                    continue;
                }

                $changes = $domain->changedFields();

                if ( ! $domain->update()) {
                    $failures++;
                    $this->warn("{$row['domain']}: " . $domain->getError());
                    continue;
                }

                if ( ! $this->isDryRun()) {
                    // update() zeroes the mask on success, so it is passed
                    // explicitly -- see Domain::updateDB()
                    $domain->updateDB($row['domain'], $this->userId(), true, $changes);
                }

                $this->record("{$row['domain']} updated", [
                    'domain' => $row['domain'],
                    'ns'     => array_keys((array) $domain->get('ns')),
                    'tech'   => array_keys((array) $domain->get('tech')),
                    'admin'  => $domain->get('admin'),
                ]);
            }
        });

        return $failures > 0 ? DOMAIN_UPDATE_FAILED : 0;
    }

    /**
     * One change set per domain: the options, unless a file row overrides the
     * nameserver parts, in the 'domain;add;remove' form -- for rolling
     * different nameservers onto different domains in one run.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        $defaults = [
            'add_ns'   => $this->split((string) $this->option('add-ns', '')),
            'rem_ns'   => $this->split((string) $this->option('rem-ns', '')),
            'add_tech' => $this->split((string) $this->option('add-tech', '')),
            'rem_tech' => $this->split((string) $this->option('rem-tech', '')),
            'tech'     => $this->hasOption('tech') ? $this->split((string) $this->option('tech')) : null,
            'admin'    => $this->hasOption('admin') ? (string) $this->option('admin') : null,
            'authinfo' => $this->hasOption('authinfo') ? (string) $this->option('authinfo') : null,
        ];

        $rows = [];
        foreach ($this->names() as $line) {
            if ( ! str_contains($line, ';')) {
                $rows[] = ['domain' => $line] + $defaults;
                continue;
            }

            $fields = array_map('trim', explode(';', $line));
            $rows[] = [
                'domain' => $fields[0],
                'add_ns' => isset($fields[1]) ? $this->split($fields[1]) : $defaults['add_ns'],
                'rem_ns' => isset($fields[2]) ? $this->split($fields[2]) : $defaults['rem_ns'],
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
