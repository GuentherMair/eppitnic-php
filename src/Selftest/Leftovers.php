<?php

namespace Eppitnic\Selftest;

/**
 * What a run could not clean up, and where that is written down.
 *
 * A run can only partly clean up after itself, and which part depends on what
 * it got as far as doing. A contact that was on a domain when that domain was
 * deleted stays linked until the domain is purged, 30 days later
 * (redemptionPeriod, then pendingDelete).
 * A contact that was never on one -- because the run created it and then
 * failed, or because it was swapped off the domain before the delete -- is
 * free immediately, and making it wait out a window that applies to something
 * else would be an invented delay.
 *
 * So the note distinguishes the two, and the waiting attaches to the blockage
 * rather than to the run: `blocked_since` is when the domain was deleted, not
 * when the run started. Those are the same moment for a run that finished, and
 * are days apart when a leftover domain is only deleted by a later reap.
 *
 * The note is a file rather than a row in the database, deliberately. The
 * self-test's own bookkeeping is not the operator's data, and it should not
 * turn up in a domain export, an ownership check or a backup. It is also the
 * only part of this that has to survive when the run itself did not: a run
 * killed halfway leaves the same note as one that finished.
 *
 * Nothing here reaches the registry. The note says what exists; the reap
 * command decides what to do about it.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Leftovers
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Leftovers
{
    /**
     * How long nic.it takes to be done with a deleted domain, and so how long
     * the contacts that were on it stay linked: 30 days, spent in
     * redemptionPeriod and then pendingDelete. Nothing is freed until the end
     * of it, so a reap that runs earlier simply finds everything refused.
     */
    public const PURGE_DAYS = 30;

    /** set only by useDirectory(), for tests */
    private static ?string $directory = null;

    /**
     * Where the notes live. Under the checkout rather than in the system
     * temporary directory: these have to outlive a reboot by more than a week.
     */
    public static function directory(): string {
        return self::$directory ?? EPPITNIC_ROOT . '/var/selftest';
    }

    /**
     * Keep the notes somewhere else. Test suite only -- the real location is
     * not configurable, because a note written where the next reap will not
     * look for it is worse than no note at all.
     */
    public static function useDirectory(?string $path): void {
        self::$directory = $path;
    }

    // -----------------------------------------------------------------
    // writing one
    // -----------------------------------------------------------------

    /**
     * Note what a run made and could not remove.
     *
     * @return string|null the file written, or null if there was nothing to
     *                     write or it could not be -- worth reporting, but
     *                     not a test failure
     */
    public static function record(Run $run): ?string {
        // a run that made nothing -- or cleaned up everything it made -- has
        // nothing to come back for, and an empty note is one more file for the
        // next reap to open and discard
        if ($run->contacts() === [] && $run->domains() === []) {
            return null;
        }

        $linked = $run->linkedContacts();

        return self::write($run->names->stamp, [
            'stamp'         => $run->names->stamp,
            'made_at'       => date('c'),
            'endpoint'      => Guard::endpoint(),
            'domains'       => array_values($run->domains()),
            'contacts'      => array_values(array_diff(array_keys($run->contacts()), $linked)),
            'linked'        => array_values($linked),
            'blocked_since' => $run->blockedSince() === null ? null : date('c', $run->blockedSince()),
        ]);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return string|null the file written, or null if it could not be
     */
    private static function write(string $stamp, array $manifest): ?string {
        $directory = self::directory();

        if ( ! is_dir($directory) && ! @mkdir($directory, 0o770, true) && ! is_dir($directory)) {
            return null;
        }

        $path = $directory . '/' . $stamp . '.json';
        $written = @file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $written === false ? null : $path;
    }

    // -----------------------------------------------------------------
    // reading them back
    // -----------------------------------------------------------------

    /**
     * Every note on file, oldest run first.
     *
     * Nothing is filtered out here: a note may hold objects that are free now
     * and objects that are not, and only the caller knows how long it is
     * willing to wait. `ripe_in_days` is how much longer the linked ones have
     * to sit, 0 when they are ready.
     *
     * @return array<int, array{stamp: string, path: string, age_days: int,
     *               domains: string[], contacts: string[], linked: string[],
     *               ripe_in_days: int}>
     */
    public static function pending(): array {
        $found = [];

        foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
            $manifest = json_decode((string) @file_get_contents($path), true);
            if ( ! is_array($manifest) || ! isset($manifest['stamp'])) {
                continue;
            }

            // the stamp is the authority on when the run happened, not the
            // file's mtime: a note rewritten by a partial reap is not younger
            $madeAt = Naming::timeOfStamp((string) $manifest['stamp'])
                ?: strtotime((string) ($manifest['made_at'] ?? ''));
            if ( ! $madeAt) {
                continue;
            }

            $found[$madeAt] = [
                'stamp'        => (string) $manifest['stamp'],
                'path'         => $path,
                'age_days'     => (int) floor((time() - $madeAt) / 86400),
                'domains'      => array_values((array) ($manifest['domains'] ?? [])),
                'contacts'     => array_values((array) ($manifest['contacts'] ?? [])),
                'linked'       => array_values((array) ($manifest['linked'] ?? [])),
                'ripe_in_days' => self::ripeInDays($manifest),
            ];
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * How many more days the linked contacts have to wait.
     *
     * Measured from when their domain was deleted. A note with no
     * `blocked_since` -- one written before a domain was ever deleted, or by
     * an earlier version of this file -- has nothing waiting on a purge, so
     * the answer is none.
     *
     * @param array<string, mixed> $manifest
     */
    private static function ripeInDays(array $manifest): int {
        $blockedSince = strtotime((string) ($manifest['blocked_since'] ?? ''));

        if ($blockedSince === false || $manifest['linked'] === [] || ! isset($manifest['linked'])) {
            return 0;
        }
        $elapsed = (time() - $blockedSince) / 86400;

        return (int) max(0, ceil(self::PURGE_DAYS - $elapsed));
    }

    // -----------------------------------------------------------------
    // settling up
    // -----------------------------------------------------------------

    /**
     * Write back what is still outstanding, or remove the note when nothing
     * is. Called after a reap has done what it could.
     *
     * @param string[] $contacts handles still not deleted, and not blocked
     * @param string[] $linked handles still held by a domain
     * @param string[] $domains domains still not deleted
     * @param bool $domainJustDeleted whether this reap deleted a domain -- if
     *             it did, the contacts it held start their purge wait now,
     *             not when the run that made them happened to start
     */
    public static function settle(
        string $path,
        array $contacts,
        array $linked,
        array $domains,
        bool $domainJustDeleted = false,
    ): void {
        if ($contacts === [] && $linked === [] && $domains === []) {
            @unlink($path);
            return;
        }

        $manifest = json_decode((string) @file_get_contents($path), true);
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest['contacts'] = array_values($contacts);
        $manifest['linked'] = array_values($linked);
        $manifest['domains'] = array_values($domains);

        if ($domainJustDeleted) {
            $manifest['blocked_since'] = date('c');
        }

        self::write((string) ($manifest['stamp'] ?? basename($path, '.json')), $manifest);
    }
}
