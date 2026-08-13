<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Contact;

/**
 * Everything the registry holds about a contact.
 */
final class ContactInfoCommand extends Command
{
    public function describe(): string {
        return 'show registry information for contacts';
    }

    public function arguments(): string {
        return '<handle>...';
    }

    public function options(): array {
        return [
            'file=' => 'read handles from this file, one per line',
            'store' => 'write what was fetched to the local database',
        ];
    }

    public function run(): int {
        $handles = $this->names();
        if ($handles === []) {
            throw new UsageError('give at least one contact handle, or --file=PATH');
        }

        $store = $this->hasOption('store');
        $userId = $this->userId();
        $failures = 0;

        $this->withSession(function ($nic) use ($handles, $store, $userId, &$failures) {
            foreach ($handles as $handle) {
                $contact = new Contact($nic);

                if ( ! $contact->fetch($handle)) {
                    $failures++;
                    $this->warn("{$handle}: " . ($contact->getError() ?: 'not found'));
                    continue;
                }

                $record = [
                    'handle'               => $contact->get('handle'),
                    'name'                 => $contact->get('name'),
                    'org'                  => $contact->get('org'),
                    'street'               => array_values(array_filter([
                        $contact->get('street'), $contact->get('street2'), $contact->get('street3'),
                    ], fn($v) => (string) $v !== '')),
                    'city'                 => $contact->get('city'),
                    'province'             => $contact->get('province'),
                    'postalcode'           => $contact->get('postalcode'),
                    'countrycode'          => $contact->get('countrycode'),
                    'voice'                => $contact->get('voice'),
                    'fax'                  => $contact->get('fax'),
                    'email'                => $contact->get('email'),
                    'status'               => $contact->get('status'),
                    'consentforpublishing' => (int) $contact->get('consentforpublishing'),
                    'nationalitycode'      => $contact->get('nationalitycode'),
                    'entitytype'           => (int) $contact->get('entitytype'),
                    'regcode'              => $contact->get('regcode'),
                ];

                $this->record($this->render($record), $record);

                if ($store) {
                    if ($contact->storeDB($userId)) {
                        $this->line('  stored locally');
                    } else {
                        $failures++;
                        $this->warn("{$handle}: not stored (" . $contact->getError() . ')');
                    }
                }
            }
        });

        return $failures > 0 ? CONTACT_FETCH_FAILED : 0;
    }

    private function render(array $c): string {
        $out = $c['handle'] . "\n";
        foreach ([
            'name' => $c['name'], 'org' => $c['org'],
            'address' => implode(', ', $c['street']),
            'city' => trim("{$c['postalcode']} {$c['city']} ({$c['province']}) {$c['countrycode']}"),
            'voice' => $c['voice'], 'fax' => $c['fax'], 'email' => $c['email'],
            'status' => implode(', ', (array) $c['status']),
            'published' => $c['consentforpublishing'] ? 'yes' : 'no',
            'entity type' => $c['entitytype'],
            'reg. code' => $c['regcode'],
        ] as $label => $value) {
            if ((string) $value !== '') {
                $out .= sprintf("  %-12s %s\n", $label, $value);
            }
        }
        return rtrim($out, "\n");
    }
}
