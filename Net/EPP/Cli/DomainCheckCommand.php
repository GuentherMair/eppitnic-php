<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Domain;

/**
 * Availability of one or more domains at the registry.
 *
 * Replaces examples/025-check-domain-bulk.php and the check step the old
 * CLI/domain.php performed before every fetch.
 */
final class DomainCheckCommand extends Command
{
    public function describe(): string {
        return 'check whether domains are available for registration';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file=' => 'read domain names from this file, one per line',
        ];
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $results = $this->withSession(function ($nic) use ($names) {
            $domain = new Domain($nic);
            $out = [];

            // the registry caps a single <check> at five names, which is why
            // Domain::check() slices; batching here keeps that invisible to
            // the caller, who may pass a file of any length
            foreach (array_chunk($names, 5) as $batch) {
                $answer = $domain->check($batch);

                if ( ! is_array($answer)) {
                    // one name in the batch, or an outright failure
                    $out[$batch[0]] = ($answer === -1 || $answer === -2)
                        ? ['available' => null, 'reason' => $domain->getError() ?: 'check failed']
                        : ['available' => (bool) $answer, 'reason' => $answer ? 'OK' : (string) $domain->svMsg];
                    continue;
                }
                foreach ($answer as $name => $result) {
                    $out[$name] = $result;
                }
            }
            return $out;
        });

        $failed = false;
        foreach ($results as $name => $result) {
            if ($result['available'] === null) {
                $failed = true;
                $this->warn("{$name}: check failed ({$result['reason']})");
                continue;
            }
            $this->record(
                sprintf('%-40s %s%s', $name, $result['available'] ? 'available' : 'taken',
                    $result['available'] ? '' : ' (' . $result['reason'] . ')'),
                ['domain' => $name] + $result
            );
        }

        return $failed ? DOMAIN_CHECK_FAILED : 0;
    }
}
