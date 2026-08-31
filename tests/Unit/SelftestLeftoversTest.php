<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Selftest\Leftovers;
use Eppitnic\Selftest\Naming;
use Eppitnic\Selftest\Run;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * The note a run leaves behind, and what a later reap makes of it.
 *
 * This is the only record that a run happened which survives the run itself.
 * A contact attached to a domain cannot be deleted for another week, so the
 * cleanup necessarily happens in a different process on a different day, and
 * everything it needs to know has to be in this file.
 */
final class SelftestLeftoversTest extends EppTestCase
{
    private string $directory;

    protected function setUp(): void {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/eppitnic-selftest-' . bin2hex(random_bytes(6));
        Leftovers::useDirectory($this->directory);
    }

    protected function tearDown(): void {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
        Leftovers::useDirectory(null);

        parent::tearDown();
    }

    /**
     * @param int $at when the run it describes started
     */
    private function noteFrom(int $at, array $contacts, array $domains = [], array $blocked = []): Run {
        $run = new Run($this->nic, new Naming($at));

        foreach ($contacts as $handle) {
            $run->noteContact($handle, 'registrant');
        }
        foreach ($domains as $domain) {
            $run->noteDomain($domain);
        }
        if ($blocked !== []) {
            $run->blockContacts($blocked);
        }
        Leftovers::record($run);

        return $run;
    }

    private static function daysAgo(int $days): int {
        return time() - $days * 86400;
    }

    // ---------------------------------------------------------------
    // writing one
    // ---------------------------------------------------------------

    public function testARunLeavesANoteOfWhatItMade(): void {
        $run = $this->noteFrom(self::daysAgo(10), ['STAAAAAAR1'], ['st-x-1.it']);

        $path = Leftovers::record($run);

        $this->assertNotNull($path);
        $written = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['STAAAAAAR1'], $written['contacts']);
        $this->assertSame(['st-x-1.it'], $written['domains']);
    }

    /**
     * A run that made nothing -- or cleaned up everything it made -- has
     * nothing to come back for. An empty note is one more file for the next
     * reap to open and discard.
     */
    public function testARunWithNothingOutstandingLeavesNoNote(): void {
        $run = new Run($this->nic, new Naming(self::daysAgo(10)));

        $this->assertNull(Leftovers::record($run));
        $this->assertSame([], Leftovers::pending());
    }

    public function testARunThatCleanedUpAfterItselfLeavesNoNote(): void {
        $run = new Run($this->nic, new Naming(self::daysAgo(10)));
        $run->noteContact('STAAAAAAD1', 'disposable');
        $run->forgetContact('STAAAAAAD1');

        $this->assertNull(Leftovers::record($run));
    }

    /**
     * The directory does not exist on a fresh checkout, and a run must not
     * fail because of it.
     */
    public function testTheDirectoryIsCreatedOnDemand(): void {
        $this->assertDirectoryDoesNotExist($this->directory);

        $this->noteFrom(self::daysAgo(10), ['STAAAAAAR1']);

        $this->assertDirectoryExists($this->directory);
    }

    /**
     * A read-only checkout is a real deployment. Losing the note is worth
     * reporting, but the run's actual work was already done.
     */
    public function testAnUnwritableDirectoryIsReportedRatherThanFatal(): void {
        Leftovers::useDirectory('/proc/nonexistent/eppitnic');
        $run = new Run($this->nic, new Naming());
        $run->noteContact('STAAAAAAR1', 'registrant');

        $this->assertNull(Leftovers::record($run));
    }

    // ---------------------------------------------------------------
    // finding them again
    // ---------------------------------------------------------------

    /**
     * The case that prompted this: a run that fell over before creating its
     * domain holds nothing, so nothing has to wait. Making these sit out a
     * purge window that applies to a domain nobody created would be a delay
     * this code invented.
     */
    public function testContactsFromARunWithNoDomainAreReadyImmediately(): void {
        $this->noteFrom(time(), ['STAAAAAAA1', 'STAAAAAAT1']);

        $pending = Leftovers::pending();

        $this->assertCount(1, $pending);
        $this->assertSame(['STAAAAAAA1', 'STAAAAAAT1'], $pending[0]['contacts']);
        $this->assertSame([], $pending[0]['linked']);
        $this->assertSame(0, $pending[0]['ripe_in_days'], 'nothing was blocked, so nothing should wait');
    }

    /**
     * Only what was on the domain when it was deleted waits. A contact the run
     * swapped off the domain earlier is associated with nothing.
     */
    public function testOnlyBlockedContactsWait(): void {
        $this->noteFrom(time(), ['STAAAAAAR1', 'STAAAAAAA1'], [], ['STAAAAAAR1']);

        $pending = Leftovers::pending();

        $this->assertSame(['STAAAAAAA1'], $pending[0]['contacts'], 'a free contact was filed as blocked');
        $this->assertSame(['STAAAAAAR1'], $pending[0]['linked']);
        $this->assertGreaterThan(0, $pending[0]['ripe_in_days']);
    }

    public function testABlockedContactBecomesReadyOnceThePurgeWindowHasPassed(): void {
        $this->noteFrom(time(), ['STAAAAAAR1'], [], ['STAAAAAAR1']);
        $path = Leftovers::pending()[0]['path'];

        $manifest = json_decode((string) file_get_contents($path), true);
        $manifest['blocked_since'] = date('c', self::daysAgo(Leftovers::PURGE_DAYS + 1));
        file_put_contents($path, json_encode($manifest));

        $this->assertSame(0, Leftovers::pending()[0]['ripe_in_days']);
    }

    /**
     * The wait runs from the domain's deletion, not from the run. A leftover
     * domain that only a later reap deletes starts its contacts' clock then,
     * however old the run itself is.
     */
    public function testTheWaitRunsFromTheDeletionNotTheRun(): void {
        $this->noteFrom(self::daysAgo(40), ['STAAAAAAR1'], [], ['STAAAAAAR1']);

        $found = Leftovers::pending()[0];

        $this->assertGreaterThanOrEqual(40, $found['age_days'], 'the run really is that old');
        $this->assertGreaterThan(0, $found['ripe_in_days'], 'but its domain was only just deleted');
    }

    /**
     * Oldest first, so a reap that is interrupted has cleared the leftovers
     * that have been sitting at the registry longest.
     */
    public function testTheOldestRunComesFirst(): void {
        $this->noteFrom(self::daysAgo(10), ['STNEWER1X']);
        $this->noteFrom(self::daysAgo(40), ['STOLDER1X']);

        $ages = array_column(Leftovers::pending(), 'age_days');

        $this->assertCount(2, $ages);
        $this->assertGreaterThan($ages[1], $ages[0], 'the older run was not reaped first');
    }

    /**
     * A note written before blocked contacts were tracked separately lists no
     * `linked` set. Reading its contacts as free is right: the registry has
     * the final say, and a refusal simply leaves them on file.
     */
    public function testANoteWithoutABlockedSetIsReadAsUnblocked(): void {
        mkdir($this->directory, 0o770, true);
        file_put_contents(
            $this->directory . '/TJC6F4.json',
            '{"stamp":"TJC6F4","contacts":["STTJC6F4R1"],"domains":[]}'
        );

        $found = Leftovers::pending()[0];

        $this->assertSame(['STTJC6F4R1'], $found['contacts']);
        $this->assertSame(0, $found['ripe_in_days']);
    }

    public function testGarbageInTheDirectoryIsIgnored(): void {
        mkdir($this->directory, 0o770, true);
        file_put_contents($this->directory . '/not-json.json', 'this is not json');
        file_put_contents($this->directory . '/no-stamp.json', '{"contacts":["X"]}');

        $this->assertSame([], Leftovers::pending());
    }

    // ---------------------------------------------------------------
    // settling up
    // ---------------------------------------------------------------

    public function testANoteIsRemovedOnceNothingIsOutstanding(): void {
        $this->noteFrom(self::daysAgo(10), ['STAAAAAAR1']);
        $path = Leftovers::pending()[0]['path'];

        Leftovers::settle($path, [], [], []);

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], Leftovers::pending());
    }

    /**
     * A partial reap has to leave the rest findable, or the leftovers it could
     * not delete become invisible and stay at the registry for good.
     */
    public function testWhatIsStillHeldStaysOnFile(): void {
        $this->noteFrom(self::daysAgo(10), ['STAAAAAAR1', 'STAAAAAAA1', 'STAAAAAAT1']);
        $path = Leftovers::pending()[0]['path'];

        Leftovers::settle($path, ['STAAAAAAT1'], [], []);

        $this->assertFileExists($path);
        $this->assertSame(['STAAAAAAT1'], Leftovers::pending()[0]['contacts']);
    }

    /**
     * Rewriting a note must not make the run look younger than it is -- the
     * stamp is the authority on age, not the file.
     */
    public function testSettlingDoesNotResetTheRunsAge(): void {
        $this->noteFrom(self::daysAgo(30), ['STAAAAAAR1', 'STAAAAAAA1']);
        $before = Leftovers::pending()[0]['age_days'];

        Leftovers::settle(Leftovers::pending()[0]['path'], ['STAAAAAAA1'], [], []);

        $this->assertSame($before, Leftovers::pending()[0]['age_days']);
    }

    // ---------------------------------------------------------------
    // EPPITNIC_VAR_DIR
    // ---------------------------------------------------------------

    public function testDirectoryHonoursEppitnicVarDirWhenNoSeamIsSet(): void {
        Leftovers::useDirectory(null);
        putenv('EPPITNIC_VAR_DIR=/data/var');
        try {
            $this->assertSame('/data/var/selftest', Leftovers::directory());
        } finally {
            putenv('EPPITNIC_VAR_DIR');
            Leftovers::useDirectory($this->directory);
        }
    }

    public function testExplicitSeamStillWinsOverEppitnicVarDir(): void {
        putenv('EPPITNIC_VAR_DIR=/should-not-be-used');
        try {
            $this->assertSame($this->directory, Leftovers::directory());
        } finally {
            putenv('EPPITNIC_VAR_DIR');
        }
    }

    public function testDirectoryDefaultsToVarSelftestWhenEnvVarIsUnset(): void {
        Leftovers::useDirectory(null);
        putenv('EPPITNIC_VAR_DIR');
        try {
            $this->assertSame(EPPITNIC_ROOT . '/var/selftest', Leftovers::directory());
        } finally {
            Leftovers::useDirectory($this->directory);
        }
    }
}
