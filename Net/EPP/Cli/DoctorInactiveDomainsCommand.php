<?php

namespace Net\EPP\Cli;

use Net\EPP\IT\Domain;
use RedBeanPHP\R;

/**
 * Domains deactivated locally that the registry still holds.
 *
 * A local soft-delete (`domains`.`active` = 0) is not a registry deletion, so
 * these are domains still being paid for that nothing here shows any more.
 * Replaces CLI/domain-GetLocallyInactiveDomains.php.
 */
final class DoctorInactiveDomainsCommand extends Command
{
    public function describe(): string {
        return 'find locally deactivated domains that still exist at the registry';
    }

    public function run(): int {
        $this->database();

        $names = R::getCol('SELECT domain FROM domains WHERE active = 0 ORDER BY domain');
        if ($names === []) {
            $this->line('no locally deactivated domains');
            return 0;
        }

        $found = $this->withSession(function ($nic) use ($names) {
            $domain = new Domain($nic);
            $still = [];

            foreach (array_chunk($names, 5) as $batch) {
                $answer = $domain->check($batch);
                $availability = is_array($answer) ? $answer : [$batch[0] => ['available' => $answer === true]];

                foreach ($availability as $name => $result) {
                    // available means the registry does not have it, which is
                    // what a deactivated domain should look like
                    if ( ! empty($result['available'])) {
                        continue;
                    }
                    $record = ['domain' => $name, 'status' => []];
                    $detail = new Domain($nic);
                    if ($detail->fetch($name)) {
                        $record['status'] = $detail->get('status');
                        $record['ex_date'] = $detail->get('exDate');
                    }
                    $still[] = $record;
                }
            }
            return $still;
        });

        foreach ($found as $record) {
            $this->record(
                sprintf('%-32s %s%s', $record['domain'],
                    implode(', ', (array) $record['status']) ?: 'still registered',
                    isset($record['ex_date']) ? '  expires ' . $record['ex_date'] : ''),
                $record
            );
        }

        if ($found === []) {
            $this->line(count($names) . ' deactivated domain(s), none still at the registry');
            return 0;
        }

        $this->line('');
        $this->line(count($found) . ' of ' . count($names) . ' deactivated domain(s) still exist at the registry');
        return DATA_INCONSISTENT;
    }
}
