<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Selftest\Naming;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Step;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * How a run decides what a step's outcome means -- the reason the self-test can
 * be run repeatedly. A contact still attached to a deleted domain cannot be
 * removed for a week, so that refusal is not a failure; else every run ends
 * red.
 */
final class SelftestRunTest extends EppTestCase
{
    private function newRun(?callable $reporter = null): Run {
        return new Run($this->nic, new Naming(1786000000), $reporter);
    }

    // ---------------------------------------------------------------
    // outcomes
    // ---------------------------------------------------------------

    public function testASucceedingStepIsOk(): void {
        $run = $this->newRun();

        $this->assertTrue($run->step('contact create', 'STTJC6F4D1', fn() => 'authinfo set'));

        $step = $run->steps()[0];
        $this->assertSame(Step::OK, $step->status);
        $this->assertSame('authinfo set', $step->detail);
        $this->assertSame(0, $run->failures());
    }

    public function testAStepThatCannotMeetItsExpectationFails(): void {
        $run = $this->newRun();

        $ok = $run->step('contact check', 'STTJC6F4D1', function () use ($run) {
            $run->expect(false, 'the handle is already taken');
            return 'free';
        });

        $this->assertFalse($ok);
        $this->assertSame(Step::FAILED, $run->steps()[0]->status);
        $this->assertSame('the handle is already taken', $run->steps()[0]->detail);
        $this->assertSame(1, $run->failures());
    }

    /**
     * The case the whole design turns on.
     */
    public function testARefusedDeferrableStepIsNotAFailure(): void {
        $run = $this->newRun();

        $ok = $run->deferrable('contact delete', 'STTJC6F4R1', 'still linked to the domain', function () use ($run) {
            $run->expect(false, 'Object association prohibits operation');
            return '';
        });

        $this->assertFalse($ok);
        $this->assertSame(Step::DEFERRED, $run->steps()[0]->status);
        $this->assertSame(0, $run->failures(), 'an expected refusal was counted as a failure');
    }

    /**
     * Its detail has to carry both what the registry said and what that means,
     * because on its own "Object association prohibits operation" reads like a
     * fault.
     */
    public function testADeferredStepExplainsItself(): void {
        $run = $this->newRun();

        $run->deferrable('contact delete', 'STTJC6F4R1', 'retry in 30 days', function () use ($run) {
            $run->expect(false, 'Object association prohibits operation');
        });

        $this->assertSame(
            'Object association prohibits operation (retry in 30 days)',
            $run->steps()[0]->detail
        );
    }

    /**
     * A deferrable step that succeeds is an ordinary success -- and the more
     * interesting result, since it means something at the registry is not what
     * this code assumes.
     */
    public function testADeferrableStepThatSucceedsIsOk(): void {
        $run = $this->newRun();

        $this->assertTrue($run->deferrable('contact delete', 'X', 'why', fn() => 'gone'));
        $this->assertSame(Step::OK, $run->steps()[0]->status);
    }

    /**
     * An unexpected exception is that step's failure, not the run's: if it
     * escaped, objects already created at the registry would be abandoned
     * without the note that says they exist and is the only way to find them.
     */
    public function testAnUnexpectedExceptionFailsOnlyItsOwnStep(): void {
        $run = $this->newRun();

        $ok = $run->step('contact create', 'X', function () {
            throw new \TypeError('something in the library gave way');
        });

        $this->assertFalse($ok);
        $this->assertSame(Step::FAILED, $run->steps()[0]->status);
        $this->assertStringContainsString('TypeError', $run->steps()[0]->detail);
        $this->assertStringContainsString('something in the library gave way', $run->steps()[0]->detail);

        // and the run carries on
        $this->assertTrue($run->step('contact delete', 'X', fn() => 'done'));
    }

    /**
     * Even in a deferrable step: an unexpected exception is not the registry
     * declining something, and must not be filed as one.
     */
    public function testAnUnexpectedExceptionIsNeverDeferred(): void {
        $run = $this->newRun();

        $run->deferrable('contact delete', 'X', 'still linked', function () {
            throw new \RuntimeException('not a refusal');
        });

        $this->assertSame(Step::FAILED, $run->steps()[0]->status);
        $this->assertSame(1, $run->failures());
    }

    public function testASkippedStepIsNeitherOkNorFailed(): void {
        $run = $this->newRun();
        $run->skip('domain update', 'st-tjc6f4-1.it', 'the contacts could not be created');

        $this->assertSame(Step::SKIPPED, $run->steps()[0]->status);
        $this->assertSame(0, $run->failures());
        $this->assertSame(1, $run->summary()['skipped']);
    }

    // ---------------------------------------------------------------
    // ensure(), and what a failure says
    // ---------------------------------------------------------------

    /**
     * ensure() takes the object so the registry's own answer becomes the
     * step's detail. A step that only said "it failed" would send the reader
     * to the registry's logs for something already in hand.
     */
    public function testEnsureReportsWhatTheRegistrySaid(): void {
        $run = $this->newRun();
        $contact = new Contact($this->nic);
        $contact->svCode = '2303';
        $contact->svMsg = 'Object does not exist';

        $run->step('contact info', 'NOPE', function () use ($run, $contact) {
            $run->ensure(false, $contact);
        });

        $this->assertStringContainsString('2303', $run->steps()[0]->detail);
        $this->assertStringContainsString('Object does not exist', $run->steps()[0]->detail);
    }

    /**
     * A refusal with nothing attached still has to read as a sentence.
     */
    public function testEnsureSaysSomethingWhenTheRegistryDidNot(): void {
        $run = $this->newRun();
        $contact = new Contact($this->nic);
        $contact->svCode = '';

        $run->step('contact info', 'NOPE', fn() => $run->ensure(false, $contact));

        $this->assertNotSame('', trim($run->steps()[0]->detail));
    }

    public function testEnsurePassesWhenTheOperationWorked(): void {
        $run = $this->newRun();

        $this->assertTrue($run->step('contact create', 'X', fn() => $run->ensure(true, new Contact($this->nic))));
    }

    // ---------------------------------------------------------------
    // reporting and the summary
    // ---------------------------------------------------------------

    /**
     * Steps are reported as they finish, not collected and printed at the end:
     * a full run is minutes of remote round trips.
     */
    public function testEachStepIsReportedAsItFinishes(): void {
        $seen = [];
        $run = $this->newRun(function (Step $step) use (&$seen) {
            $seen[] = $step->label;
        });

        $run->step('one', '', fn() => null);
        $this->assertSame(['one'], $seen, 'the first step was not reported before the second ran');

        $run->step('two', '', fn() => null);
        $this->assertSame(['one', 'two'], $seen);
    }

    public function testTheSummaryCountsEveryOutcome(): void {
        $run = $this->newRun();
        $run->step('a', '', fn() => null);
        $run->step('b', '', fn() => $run->expect(false, 'no'));
        $run->deferrable('c', '', 'why', fn() => $run->expect(false, 'no'));
        $run->skip('d', '', 'why');

        $summary = $run->summary();

        $this->assertSame(4, $summary['steps']);
        $this->assertSame(1, $summary['ok']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['deferred']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('TJC6F4', $summary['stamp']);
    }

    /**
     * The summary is what the leftover note is written from, so what the run
     * made has to survive in it.
     */
    public function testTheSummaryListsWhatTheRunMade(): void {
        $run = $this->newRun();
        $run->noteContact('STTJC6F4R1', 'registrant');
        $run->noteContact('STTJC6F4A1', 'admin');
        $run->noteDomain('st-tjc6f4-1.it');

        $this->assertSame(['STTJC6F4R1', 'STTJC6F4A1'], $run->summary()['contacts']);
        $this->assertSame(['st-tjc6f4-1.it'], $run->summary()['domains']);
    }

    /**
     * The duration is per step, not per run: a self-test that got slower is
     * worth knowing about, and the answer to "which part" is on the line.
     */
    /**
     * The note is written from what is still outstanding, so anything the run
     * did delete must drop out of it -- otherwise `selftest reap` retries it
     * for weeks against "object does not exist", reporting problems it
     * invented.
     */
    public function testWhatWasDeletedIsNoLongerOutstanding(): void {
        $run = $this->newRun();
        $run->noteContact('STTJC6F4D1', 'disposable');
        $run->noteContact('STTJC6F4R1', 'registrant');
        $run->noteDomain('st-tjc6f4-1.it');

        $run->forgetContact('STTJC6F4D1');
        $run->forgetDomain('st-tjc6f4-1.it');

        $this->assertSame(['STTJC6F4R1'], $run->summary()['contacts']);
        $this->assertSame([], $run->summary()['domains']);
    }

    public function testForgettingSomethingNeverNotedChangesNothing(): void {
        $run = $this->newRun();
        $run->noteContact('STTJC6F4R1', 'registrant');

        $run->forgetContact('SOMEBODY-ELSE');
        $run->forgetDomain('example.it');

        $this->assertSame(['STTJC6F4R1'], $run->summary()['contacts']);
    }

    public function testEveryStepIsTimed(): void {
        $run = $this->newRun();
        $run->step('a', '', fn() => usleep(2000));

        $this->assertGreaterThan(0.001, $run->steps()[0]->seconds);
    }

    public function testAStepBecomesData(): void {
        $run = $this->newRun();
        $run->step('contact create', 'STTJC6F4D1', fn() => 'made');

        $this->assertSame(
            ['step' => 'contact create', 'status' => 'ok', 'subject' => 'STTJC6F4D1', 'detail' => 'made'],
            array_diff_key($run->steps()[0]->toArray(), ['seconds' => null])
        );
    }
}
