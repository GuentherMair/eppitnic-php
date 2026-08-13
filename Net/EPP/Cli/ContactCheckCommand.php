<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Contact;

/**
 * Whether contact handles are free at the registry.
 */
final class ContactCheckCommand extends Command
{
    public function describe(): string {
        return 'check whether contact handles are available';
    }

    public function arguments(): string {
        return '<handle>...';
    }

    public function options(): array {
        return [
            'file=' => 'read handles from this file, one per line',
        ];
    }

    public function run(): int {
        $handles = $this->names();
        if ($handles === []) {
            throw new UsageError('give at least one contact handle, or --file=PATH');
        }

        $results = $this->withSession(function ($nic) use ($handles) {
            $contact = new Contact($nic);
            $out = [];

            // same five-per-request cap as domain:check
            foreach (array_chunk($handles, 5) as $batch) {
                $answer = $contact->check($batch);

                if ( ! $answer->answered()) {
                    // every handle in the batch is unknown, not "taken"
                    foreach ($batch as $handle) {
                        $out[$handle] = null;
                    }
                    continue;
                }
                foreach ($answer->all() as $handle => $result) {
                    $out[$handle] = $result['available'];
                }
            }
            return $out;
        });

        $failed = false;
        foreach ($results as $handle => $available) {
            if ($available === null) {
                $failed = true;
                $this->warn("{$handle}: check failed");
                continue;
            }
            $this->record(
                sprintf('%-24s %s', $handle, $available ? 'available' : 'in use'),
                ['handle' => $handle, 'available' => $available]
            );
        }

        return $failed ? CONTACT_CHECK_FAILED : 0;
    }
}
