<?php

namespace Eppitnic\Selftest;

/**
 * One registry operation, and how it went -- not simply pass or fail.
 * `deferred` is for a contact still held by a deleted domain, where the refusal
 * is the registry being right and a failure would make every run red.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Step
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Step
{
    public const OK       = 'ok';
    public const FAILED   = 'failed';
    public const DEFERRED = 'deferred';
    public const SKIPPED  = 'skipped';

    /**
     * @param string $label what was attempted, e.g. 'contact update'
     * @param string $status one of the four constants above
     * @param string $subject the handle or domain it was attempted on
     * @param string $detail the registry's answer, or why it was skipped
     * @param float $seconds how long the round trip took
     */
    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly string $subject = '',
        public readonly string $detail = '',
        public readonly float $seconds = 0.0,
    ) {}

    /**
     * Whether this step means the run did not do what it set out to do.
     * Only FAILED counts: see the note on DEFERRED above.
     */
    public function isFailure(): bool {
        return $this->status === self::FAILED;
    }

    /**
     * @return string the one-character mark shown at the start of its line
     */
    public function symbol(): string {
        return match ($this->status) {
            self::OK       => '+',
            self::FAILED   => 'x',
            self::DEFERRED => '~',
            self::SKIPPED  => '-',
        };
    }

    /**
     * @return array<string, mixed> the same thing as data, for --json
     */
    public function toArray(): array {
        return [
            'step'    => $this->label,
            'status'  => $this->status,
            'subject' => $this->subject,
            'detail'  => $this->detail,
            'seconds' => round($this->seconds, 3),
        ];
    }
}
