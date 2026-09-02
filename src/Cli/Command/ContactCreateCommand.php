<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Contact;

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

    /** entitytype value => what it means, for hints()'s multi-line breakdown */
    private const ENTITY_TYPES = [
        1 => 'natural person',
        2 => 'company',
        3 => 'individual enterprise or freelancer',
        4 => 'non-profit',
        5 => 'public entity',
        6 => 'other',
        7 => 'foreign entity other than a natural person',
    ];

    /**
     * Extra format/value hints for fields whose plain name isn't enough on
     * its own, keyed by option name. Source: nic.it's own technical
     * guidelines (Linee Guida Tecniche Sincrone -- the extcon:entityType
     * table, and the phone-number/province validation rules), not this
     * codebase's own choice, so a code change here would be the registry
     * changing the rule, not a preference.
     *
     * A method rather than a const: the entitytype entry is built with
     * str_repeat() to align its continuation lines under usage()'s
     * description column (25 characters in, from its "  %-22s %s" format),
     * which a class constant's compile-time expression cannot call.
     */
    private static function hints(): array {
        $pad = str_repeat(' ', 25);
        $entityLines = [];
        foreach (self::ENTITY_TYPES as $value => $meaning) {
            $entityLines[] = "{$pad}{$value} {$meaning}";
        }

        return [
            'entitytype' => "0/omitted = plain admin/tech contact, not a registrant. As a registrant:\n"
                . implode("\n", $entityLines)
                . "\n{$pad}requires --nationalitycode when 1 or higher",
            'province' => 'two-letter Italian province code (e.g. MI, RM); required by the registry when --countrycode=IT',
            'voice' => 'ISO international phone format, e.g. +39.0503139811; optional extension as x1234 ' .
                '(max 10 digits) -- fax uses the same format',
            'countrycode' => 'ISO 3166-1 country code (e.g. IT, FR, NL)',
            'nationalitycode' => "ISO 3166-1 country code of the registrant's citizenship (e.g. IT, FR, NL); " .
                'for a non-natural-person registrant (--entitytype != 1) this matches --countrycode',
        ];
    }

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
        $hints = self::hints();
        foreach (array_keys(self::FIELDS) as $field) {
            $label = $hints[$field] ?? $field;
            $options[$field . '='] = in_array($field, self::REQUIRED, true) ? "{$label} (required)" : $label;
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

            if ( ! $contact->get('authinfo')) {
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
