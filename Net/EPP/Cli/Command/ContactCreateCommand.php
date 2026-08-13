<?php

namespace Net\EPP\Cli\Command;

use Net\EPP\Cli\Command;
use Net\EPP\Cli\UsageError;
use Net\EPP\Epp\Contact;

/**
 * Create a contact at the registry.
 */
final class ContactCreateCommand extends Command
{
    /** option name => the Contact property it sets */
    private const FIELDS = [
        'name'            => 'name',
        'org'             => 'org',
        'street'          => 'street',
        'street2'         => 'street2',
        'street3'         => 'street3',
        'city'            => 'city',
        'province'        => 'province',
        'postalcode'      => 'postalcode',
        'countrycode'     => 'countrycode',
        'voice'           => 'voice',
        'fax'             => 'fax',
        'email'           => 'email',
        'authinfo'        => 'authinfo',
        'nationalitycode' => 'nationalitycode',
        'entitytype'      => 'entitytype',
        'regcode'         => 'regcode',
        'schoolcode'      => 'schoolcode',
    ];

    /** what the registry will not accept a contact without */
    private const REQUIRED = ['name', 'street', 'city', 'province', 'postalcode', 'countrycode', 'voice', 'email'];

    public function describe(): string {
        return 'create a contact at the registry';
    }

    public function arguments(): string {
        return '[<handle>]';
    }

    public function options(): array {
        $options = [
            'publish' => 'consent to publishing this contact in the public whois',
        ];
        foreach (array_keys(self::FIELDS) as $field) {
            $options[$field . '='] = in_array($field, self::REQUIRED, true) ? "{$field} (required)" : $field;
        }
        $options['store'] = 'also write the contact to the local database';

        return $options + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $missing = array_values(array_filter(self::REQUIRED, fn($f) => (string) $this->option($f, '') === ''));
        if ($missing !== []) {
            throw new UsageError('missing required field(s): --' . implode(', --', $missing));
        }

        // a registrant needs its nationality and entity type; a plain
        // admin/tech contact is entityType 0 and needs neither
        $entityType = (int) $this->option('entitytype', 0);
        if ($entityType > 0 && (string) $this->option('nationalitycode', '') === '') {
            throw new UsageError('--nationalitycode is required when --entitytype is 1 or higher');
        }

        $names = $this->names();
        $handle = $names[0] ?? null;

        if ( ! $this->confirm('Create a contact at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $store = $this->hasOption('store');
        $userId = $this->userId();
        $dryRun = $this->isDryRun();
        $created = null;

        $this->withSession(function ($nic) use ($handle, $store, $userId, $dryRun, &$created) {
            $contact = new Contact($nic);

            // a handle has to be unique at the registry, so one is generated
            // and checked unless the caller insists on a particular value
            $contact->set('handle', $handle ?? $contact->generateHandle());

            foreach (self::FIELDS as $option => $property) {
                if ($this->hasOption($option)) {
                    $contact->set($property, (string) $this->option($option));
                }
            }
            $contact->set('consentforpublishing', $this->hasOption('publish'));

            if ( ! $contact->authinfo) {
                $contact->set('authinfo', $contact->authinfo());
            }

            if ( ! $contact->create()) {
                $this->warn('create failed: ' . $contact->getError());
                return;
            }

            $created = $contact->get('handle');

            if ($store && ! $dryRun) {
                $contact->storeDB($userId);
            }

            $this->record(
                'contact ' . $created . ' created',
                ['handle' => $created, 'authinfo' => $contact->get('authinfo')]
            );
        });

        return ($created === null && ! $dryRun) ? CONTACT_CREATE_FAILED : 0;
    }
}
