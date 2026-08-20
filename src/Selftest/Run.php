<?php

namespace Eppitnic\Selftest;

use Eppitnic\Epp\AbstractObject;
use Eppitnic\Epp\Client;

/**
 * One self-test run: what it may call things, what it has done so far, and how
 * that went.
 *
 * Scenarios are written against this rather than against the Client directly,
 * so that every registry operation is timed, labelled and reported the same
 * way, and so a scenario reads as the sequence of operations it is meant to
 * describe. A step's body says what it does; the bookkeeping is here.
 *
 * Steps are reported as they finish rather than collected and printed at the
 * end. A full run is a few dozen round trips against a remote registry and
 * takes minutes; watching it arrive is the difference between a command that
 * looks slow and one that looks hung.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Run
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Run
{
    /** @var Step[] every step attempted, in order */
    private array $steps = [];

    /** @var array<string, string> handles this run created, handle => role */
    private array $contacts = [];

    /** @var string[] domains this run created */
    private array $domains = [];

    /** @var array<string, true> handles held by a domain that was deleted */
    private array $linked = [];

    /** when that domain was deleted, starting the purge wait */
    private ?int $blockedSince = null;

    /** called with each Step as it completes; null to collect silently */
    private $reporter;

    /**
     * @param Client $nic a logged-in client
     * @param Naming $names the names this run may use
     * @param callable|null $reporter function(Step $step): void
     */
    public function __construct(
        public readonly Client $nic,
        public readonly Naming $names,
        ?callable $reporter = null,
    ) {
        $this->reporter = $reporter;
    }

    // -----------------------------------------------------------------
    // running a step
    // -----------------------------------------------------------------

    /**
     * Attempt one registry operation.
     *
     * @param string $label what is being attempted, e.g. 'contact create'
     * @param string $subject the handle or domain it is attempted on
     * @param callable $work function(): ?string -- returns a note for the
     *                       line, and calls ensure() for anything that must
     *                       succeed
     * @return bool whether it succeeded
     */
    public function step(string $label, string $subject, callable $work): bool {
        return $this->attempt($label, $subject, $work, Step::FAILED, '');
    }

    /**
     * Attempt an operation whose refusal is an acceptable answer.
     *
     * The case this exists for: deleting a contact that is still attached to a
     * domain. nic.it keeps it linked until the domain finishes pendingDelete,
     * 30 days after the delete was accepted, so the refusal is the
     * registry being right. The attempt is still worth making -- one that
     * unexpectedly succeeds is the interesting result.
     *
     * @param string $why what a refusal would mean, shown on the line
     * @return bool whether it succeeded
     */
    public function deferrable(string $label, string $subject, string $why, callable $work): bool {
        return $this->attempt($label, $subject, $work, Step::DEFERRED, $why);
    }

    /**
     * Note something that was not attempted, and why.
     */
    public function skip(string $label, string $subject, string $why): void {
        $this->add(new Step($label, Step::SKIPPED, $subject, $why));
    }

    /**
     * @param string $onFailure the status to record when $work throws
     * @param string $note appended to a non-OK outcome's detail
     */
    private function attempt(string $label, string $subject, callable $work, string $onFailure, string $note): bool {
        $started = microtime(true);

        try {
            $detail = (string) ($work() ?? '');
            $status = Step::OK;
        } catch (StepFailed $e) {
            $detail = trim($e->getMessage());
            $status = $onFailure;
            if ($note !== '') {
                $detail = $detail === '' ? $note : "{$detail} ({$note})";
            }
        } catch (\Throwable $e) {
            // Anything a step did not expect is still that step's failure, not
            // the run's. Letting it escape would abandon the objects already
            // created at the registry without writing the note that says they
            // exist -- and that note is the only way anyone finds them again.
            $detail = get_class($e) . ': ' . trim($e->getMessage());
            $status = Step::FAILED;
        }

        $this->add(new Step($label, $status, $subject, $detail, microtime(true) - $started));

        return $status === Step::OK;
    }

    /**
     * Stop the current step unless $ok.
     *
     * Takes the object the operation was made on so the registry's own answer
     * -- and its EPP result code -- becomes the step's detail. Under --verbose
     * getError() also carries the request and the raw response.
     *
     * @param bool $ok what the operation returned
     * @param AbstractObject $on the Domain or Contact it was called on
     * @throws StepFailed if $ok is false
     */
    public function ensure(bool $ok, AbstractObject $on): void {
        if ($ok) {
            return;
        }
        $message = trim($on->getError());

        throw new StepFailed(
            $message === '' ? 'the registry refused it, without saying why' : $message,
            (string) $on->svCode
        );
    }

    /**
     * Stop the current step because something this code checked is not true.
     *
     * For the assertions a self-test makes about the registry's answers -- a
     * name that should be taken once it has been created, a field that should
     * read back as it was written. Nothing went wrong at the transport level,
     * so there is no object to ask for an error.
     *
     * @throws StepFailed if $ok is false
     */
    public function expect(bool $ok, string $complaint): void {
        if ( ! $ok) {
            throw new StepFailed($complaint);
        }
    }

    // -----------------------------------------------------------------
    // what the run has made
    // -----------------------------------------------------------------

    /**
     * @param string $role why this contact exists: registrant, admin, tech,
     *                     disposable
     */
    public function noteContact(string $handle, string $role): void {
        $this->contacts[$handle] = $role;
    }

    public function noteDomain(string $domain): void {
        $this->domains[] = $domain;
    }

    /**
     * Stop counting something as outstanding, because it is gone.
     *
     * What is left noted becomes the leftover note, and `selftest reap` works
     * from that. Without this an object the run *did* manage to delete would
     * be retried for weeks, each time answered with "object does not exist".
     */
    public function forgetContact(string $handle): void {
        unset($this->contacts[$handle], $this->linked[$handle]);
    }

    public function forgetDomain(string $domain): void {
        $this->domains = array_values(array_diff($this->domains, [$domain]));
    }

    /**
     * Note that these contacts were on a domain that has just been deleted,
     * and so cannot be removed until the registry purges it.
     *
     * Only what was on the domain *at the time it was deleted* counts. A
     * contact the run swapped off it earlier is not associated with anything
     * any more, and a run that never got as far as creating a domain has
     * nothing blocked at all -- making either wait out a purge window that
     * applies to something else would be an invented delay.
     *
     * @param string[] $handles the registrant, admin and tech it carried
     */
    public function blockContacts(array $handles): void {
        foreach ($handles as $handle) {
            if (isset($this->contacts[$handle])) {
                $this->linked[$handle] = true;
            }
        }
        $this->blockedSince = time();
    }

    /** @return array<string, string> handle => role */
    public function contacts(): array {
        return $this->contacts;
    }

    /** @return string[] the handles a deleted domain still holds */
    public function linkedContacts(): array {
        return array_keys($this->linked);
    }

    /** @return int|null when the purge wait started, null if nothing is waiting */
    public function blockedSince(): ?int {
        return $this->linked === [] ? null : $this->blockedSince;
    }

    /** @return string[] */
    public function domains(): array {
        return $this->domains;
    }

    // -----------------------------------------------------------------
    // how it went
    // -----------------------------------------------------------------

    /** @return Step[] */
    public function steps(): array {
        return $this->steps;
    }

    public function failures(): int {
        return count(array_filter($this->steps, fn(Step $s) => $s->isFailure()));
    }

    /**
     * @return array<string, mixed> counts by status, plus the run's stamp and
     *                              total time -- what the closing line says
     */
    public function summary(): array {
        $counts = [Step::OK => 0, Step::FAILED => 0, Step::DEFERRED => 0, Step::SKIPPED => 0];
        $seconds = 0.0;

        foreach ($this->steps as $step) {
            $counts[$step->status]++;
            $seconds += $step->seconds;
        }

        return [
            'stamp'     => $this->names->stamp,
            'steps'     => count($this->steps),
            'ok'        => $counts[Step::OK],
            'failed'    => $counts[Step::FAILED],
            'deferred'  => $counts[Step::DEFERRED],
            'skipped'   => $counts[Step::SKIPPED],
            'seconds'   => round($seconds, 2),
            'contacts'  => array_keys($this->contacts),
            'linked'    => $this->linkedContacts(),
            'domains'   => $this->domains,
        ];
    }

    private function add(Step $step): void {
        $this->steps[] = $step;

        if ($this->reporter !== null) {
            ($this->reporter)($step);
        }
    }
}
