<?php

namespace Eppitnic\Epp;

/**
 * What the registry said about one or more objects: unavailable, available, or
 * don't know. check() used to return `array|bool|int` with sentinels, and
 * `doctor inactive-domains` read a failed check as "still held".
 *
 * @category    Net
 * @package     Eppitnic\Epp\CheckResult
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
            // repeat it to read the answer. Not reset(), which takes its
            // argument by reference and a readonly property will not give it
            return count($this->availability) === 1 ? array_values($this->availability)[0] : null;
        }
        return $this->availability[$name] ?? null;
    }
}
