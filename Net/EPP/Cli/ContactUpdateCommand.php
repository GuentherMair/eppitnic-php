<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Contact;

/**
 * Change fields on existing contacts.
 *
 * Every option given is applied to every handle named, which is what makes a
 * bulk correction -- one e-mail address across a set of contacts, say -- a
 * single command.
 */
final class ContactUpdateCommand extends Command
{
    private const FIELDS = [
        'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
        'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo',
        'nationalitycode', 'entitytype', 'regcode', 'schoolcode',
    ];

    public function describe(): string {
        return 'change fields on existing contacts';
    }

    public function arguments(): string {
        return '<handle>...';
    }

    public function options(): array {
        $options = [
            'file='          => 'read handles from this file, one per line',
            'registrant-of=' => 'act on the registrants of these domains instead, colon-separated',
        ];
        foreach (self::FIELDS as $field) {
            $options[$field . '='] = "set {$field}";
        }
        $options['publish'] = 'consent to publishing in the public whois';
        $options['no-publish'] = 'withdraw that consent';
        $options['store'] = 'also update the local database row';

        return $options + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $handles = $this->names();
        $registrantOf = array_values(array_filter(array_map(
            'trim', explode(':', (string) $this->option('registrant-of', ''))
        )));

        if ($handles === [] && $registrantOf === []) {
            throw new UsageError('give at least one contact handle, --file=PATH or --registrant-of=DOMAIN');
        }

        $changes = array_values(array_filter(self::FIELDS, fn($f) => $this->hasOption($f)));
        $publish = $this->hasOption('publish');
        $unpublish = $this->hasOption('no-publish');

        if ($publish && $unpublish) {
            throw new UsageError('--publish and --no-publish are alternatives');
        }
        if ($changes === [] && ! $publish && ! $unpublish) {
            throw new UsageError('nothing to change: give at least one field option');
        }

        $subject = $registrantOf === []
            ? count($handles) . ' contact(s)'
            : 'the registrants of ' . count($registrantOf) . ' domain(s)';
        if ( ! $this->confirm("Update {$subject} at the registry?")) {
            $this->line('nothing done');
            return 0;
        }

        $store = $this->hasOption('store');
        $userId = $this->userId();
        $dryRun = $this->isDryRun();
        $failures = 0;

        $this->withSession(function ($nic) use ($handles, $registrantOf, $changes, $publish, $unpublish, $store, $userId, $dryRun, &$failures) {
            // --registrant-of names domains; the contacts to change are
            // whichever registrants they currently have, which only the
            // registry can say
            foreach ($registrantOf as $name) {
                $domain = new \Net\EPP\IT\Domain($nic);
                if ( ! $domain->fetch($name)) {
                    $failures++;
                    $this->warn("{$name}: " . ($domain->getError() ?: 'not found'));
                    continue;
                }
                $handles[] = $domain->get('registrant');
            }
            $handles = array_values(array_unique($handles));

            foreach ($handles as $handle) {
                $contact = new Contact($nic);

                // the registry only accepts a change against what it currently
                // holds, and update() diffs against the fetched state
                if ( ! $contact->fetch($handle)) {
                    $failures++;
                    $this->warn("{$handle}: " . ($contact->getError() ?: 'not found'));
                    continue;
                }

                foreach ($changes as $field) {
                    $contact->set($field, (string) $this->option($field));
                }
                if ($publish || $unpublish) {
                    $contact->set('consentforpublishing', $publish);
                }

                if ((int) $contact->get('changes') === 0) {
                    $this->line("{$handle}: already as requested");
                    continue;
                }

                if ( ! $contact->update()) {
                    $failures++;
                    $this->warn("{$handle}: " . $contact->getError());
                    continue;
                }

                if ($store && ! $dryRun) {
                    $contact->updateDB($handle, $userId, true);
                }

                $this->record("{$handle} updated", ['handle' => $handle, 'changed' => $changes]);
            }
        });

        return $failures > 0 ? CONTACT_UPDATE_FAILED : 0;
    }
}
