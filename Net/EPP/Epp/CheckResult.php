<?php

namespace Net\EPP\Epp;

/**
 * What the registry said about the availability of one or more objects.
 *
 * `check()` used to return `array|bool|int`: an array for several names, a
 * bool for one, and `-1` or `-2` for "the question was never answered" -- the
 * registry refused the command, or it was never sent. Every caller therefore
 * carried the sentinel table in its head, and one of them got it wrong:
 * `doctor inactive-domains` read a failed check as `available === false`, i.e.
 * "the registry still holds this domain", which is precisely the false report
 * that command exists to avoid.
 *
 * The three answers are unavailable, available, and don't know. This says so.
 *
 * @category    Net
 * @package     Net\EPP\Epp\CheckResult
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class CheckResult
{
    /**
     * @param array<string, array{available: bool, reason: string}> $availability
     * @param string $error empty when the registry answered
     */
    private function __construct(
        private readonly array $availability,
        private readonly string $error = ''
    ) {}

    /**
     * @param array<string, array{available: bool, reason: string}> $availability
     */
    public static function of(array $availability): self {
        return new self($availability);
    }

    /**
     * The registry did not answer the question -- it refused the command, or
     * the command was never sent.
     */
    public static function failure(string $error): self {
        return new self([], $error);
    }

    /**
     * Whether the registry answered at all. False means nothing below can be
     * trusted, and `error()` says why.
     */
    public function answered(): bool {
        return $this->error === '';
    }

    public function error(): string {
        return $this->error;
    }

    /**
     * @param string|null $name which object; omit when only one was checked
     * @return bool|null true available, false taken, null not answered
     */
    public function available(?string $name = null): ?bool {
        $entry = $this->entry($name);
        return $entry === null ? null : $entry['available'];
    }

    /**
     * The registry's explanation for an unavailable object, or the error when
     * the question went unanswered.
     *
     * @param string|null $name which object; omit when only one was checked
     */
    public function reason(?string $name = null): string {
        $entry = $this->entry($name);
        return $entry === null ? $this->error : $entry['reason'];
    }

    /**
     * @return array<string, array{available: bool, reason: string}> every
     *         answer, keyed by name; empty when the question went unanswered
     */
    public function all(): array {
        return $this->availability;
    }

    /**
     * @return string[] the names the registry reported as available
     */
    public function availableNames(): array {
        return array_keys(array_filter($this->availability, fn($a) => $a['available']));
    }

    /**
     * @return array{available: bool, reason: string}|null
     */
    private function entry(?string $name): ?array {
        if ($name === null) {
            // the single-object case: a caller that checked one name should not
            // have to repeat it to read the answer
            // not reset(): it takes its argument by reference, which a readonly
            // property will not give it
            return count($this->availability) === 1 ? array_values($this->availability)[0] : null;
        }
        return $this->availability[$name] ?? null;
    }
}
