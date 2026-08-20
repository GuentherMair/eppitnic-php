<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\Guard;
use Eppitnic\Selftest\Leftovers;
use Eppitnic\Selftest\RefusedError;

/**
 * Finish the cleanup a self-test run could not.
 *
 * Only one kind of leftover has to be waited for: a contact that was on a
 * domain when that domain was deleted, which the registry holds until the
 * domain is purged 30 days later, after redemptionPeriod and pendingDelete.
 * Everything else -- a leftover
 * domain, a contact swapped off the domain before the delete, every contact a
 * run created before failing -- is free straight away and is deleted on sight.
 *
 * That distinction is the whole point of the command. A run that fell over
 * before creating its domain has nothing blocked at all, and making its
 * contacts sit out a purge window that applies to something else would be a
 * delay this code invented.
 *
 * `--min-age` governs only the blocked ones, and is counted from the domain's
 * deletion rather than from the run. Attempting them early is harmless -- the
 * registry simply refuses again -- but it turns a command that should be
 * silent into one that reports a page of expected refusals, and a cleanup that
 * cries wolf is a cleanup nobody reads.
 *
 * @category    Net
 * @package     Eppitnic\Cli\Command\SelftestReapCommand
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SelftestReapCommand extends Command
{
    public function describe(): string {
        return 'delete what earlier self-test runs could not';
    }

    public function options(): array {
        return [
            'min-age=' => 'days to wait before retrying contacts held by a deleted domain (default '
                          . Leftovers::PURGE_DAYS . '); anything not held is deleted regardless',
            'list'     => 'show what would be deleted, and delete nothing',
            'yes'      => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        try {
            $endpoint = Guard::assertTestEnvironment();
        } catch (RefusedError $e) {
            $this->warn($e->getMessage());
            return SELFTEST_REFUSED;
        }

        $minAge = max(0, (int) $this->option('min-age', Leftovers::PURGE_DAYS));
        $pending = Leftovers::pending();
        $ready = array_values(array_filter(
            array_map(fn(array $found) => $this->ready($found, $minAge), $pending),
            fn(array $found) => $this->attemptable($found) !== []
        ));

        if ($ready === []) {
            $this->line($this->nothingMessage($pending));
            return 0;
        }
        if ($this->hasOption('list')) {
            $this->describePending($ready);
            return 0;
        }

        $objects = array_sum(array_map(fn(array $found) => count($this->attemptable($found)), $ready));

        if ( ! $this->confirm("Delete {$objects} left-over object(s) from " . count($ready) . " self-test run(s) at {$endpoint}?")) {
            $this->line('nothing done');
            return 0;
        }

        $refused = 0;
        $this->withSession(function ($nic) use ($ready, &$refused) {
            foreach ($ready as $found) {
                $refused += $this->reap($nic, $found);
            }
        });

        if ($refused > 0) {
            $this->line("{$refused} object(s) are still held; they stay on file for the next reap");
        }

        return 0;
    }

    // -----------------------------------------------------------------
    // what is ready to go
    // -----------------------------------------------------------------

    /**
     * Narrow one note to what may be attempted now.
     *
     * Domains and unblocked contacts always may. The blocked ones only once
     * their domain has had long enough to be purged -- `--min-age=0` says to
     * try anyway.
     *
     * @param array{stamp: string, path: string, age_days: int, domains: string[],
     *              contacts: string[], linked: string[], ripe_in_days: int} $found
     * @return array{stamp: string, path: string, age_days: int, domains: string[],
     *               contacts: string[], linked: string[], ripe_in_days: int}
     */
    private function ready(array $found, int $minAge): array {
        // marked, not removed: a note this reap declines to touch must still
        // list what it is holding, or the write-back loses it
        $found['attempt_linked'] = $minAge === 0 || $found['ripe_in_days'] === 0;

        return $found;
    }

    /**
     * Everything in one note this reap is willing to attempt now.
     *
     * @param array{domains: string[], contacts: string[], linked: string[],
     *              attempt_linked: bool} $found
     * @return string[]
     */
    private function attemptable(array $found): array {
        return array_merge(
            $found['domains'],
            $found['contacts'],
            $found['attempt_linked'] ? $found['linked'] : []
        );
    }

    /**
     * Why there was nothing to do -- "no self-test has ever run" and "the one
     * that did is still waiting on a purge" are different answers, and only
     * the second means come back later.
     *
     * @param array<int, array{linked: string[], ripe_in_days: int}> $pending
     */
    private function nothingMessage(array $pending): string {
        $waiting = array_filter($pending, fn(array $found) => $found['linked'] !== []);

        if ($waiting === []) {
            return 'nothing to reap';
        }
        $days = min(array_map(fn(array $found) => $found['ripe_in_days'], $waiting));
        $held = array_sum(array_map(fn(array $found) => count($found['linked']), $waiting));

        return "nothing to reap yet: {$held} contact(s) are held by a domain the registry has not purged; "
             . "try again in {$days} day(s), or --min-age=0 to attempt them now";
    }

    // -----------------------------------------------------------------
    // one run's leftovers
    // -----------------------------------------------------------------

    /**
     * @param array{stamp: string, path: string, age_days: int, domains: string[], contacts: string[]} $found
     * @return int how many objects the registry would not delete
     */
    private function reap(Client $nic, array $found): int {
        // domains first: while one exists the contacts it holds cannot be
        // freed, so attempting them before it would fail for a reason that has
        // nothing to do with how long anything has waited
        $domains = array_values(array_filter(
            $found['domains'],
            fn(string $name) => ! $this->delete($nic, $found['stamp'], 'domain', $name)
        ));
        $deletedADomain = count($domains) < count($found['domains']);

        $keep = fn(string $handle) => ! $this->delete($nic, $found['stamp'], 'contact', $handle);

        $contacts = array_values(array_filter($found['contacts'], $keep));
        $linked = $found['attempt_linked']
            ? array_values(array_filter($found['linked'], $keep))
            : $found['linked'];

        Leftovers::settle($found['path'], $contacts, $linked, $domains, $deletedADomain);

        return count($domains) + count($contacts) + count($linked);
    }

    /**
     * Delete one object, and say what happened either way.
     *
     * A refusal is expected often enough that it is not a warning: a run whose
     * domain is still in pendingDelete answers exactly this, and the object
     * simply stays on file for next time.
     *
     * @param string $kind 'domain' or 'contact'
     * @return bool whether the registry accepted it
     */
    private function delete(Client $nic, string $stamp, string $kind, string $subject): bool {
        $object = $kind === 'domain' ? new Domain($nic) : new Contact($nic);

        if ($object->delete($subject)) {
            $this->record(
                sprintf('  + %-8s %-20s deleted (run %s)', $kind, $subject, $stamp),
                ['run' => $stamp, 'object' => $kind, 'subject' => $subject, 'deleted' => true]
            );
            return true;
        }

        $error = trim($object->getError());
        $this->record(
            sprintf('  ~ %-8s %-20s still held: %s', $kind, $subject, $error),
            ['run' => $stamp, 'object' => $kind, 'subject' => $subject, 'deleted' => false, 'error' => $error]
        );

        return false;
    }

    /**
     * @param array<int, array{stamp: string, age_days: int, domains: string[],
     *              contacts: string[], linked: string[], ripe_in_days: int}> $pending
     */
    private function describePending(array $pending): void {
        foreach ($pending as $found) {
            $kinds = [
                'domain'  => $found['domains'],
                'contact' => $found['contacts'],
                'linked'  => $found['attempt_linked'] ? $found['linked'] : [],
            ];

            foreach ($kinds as $kind => $subjects) {
                foreach ($subjects as $subject) {
                    $this->record(
                        sprintf('  %-8s %-20s run %s, %d days old', $kind, $subject, $found['stamp'], $found['age_days']),
                        ['run' => $found['stamp'], 'object' => $kind, 'subject' => $subject,
                         'age_days' => $found['age_days']]
                    );
                }
            }
        }
    }
}
