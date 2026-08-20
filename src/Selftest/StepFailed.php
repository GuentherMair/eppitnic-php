<?php

namespace Eppitnic\Selftest;

/**
 * A step did not do what it set out to do.
 *
 * Thrown by Run::ensure() so a scenario reads as a straight sequence of
 * operations rather than as a ladder of `if ( ! $ok) { ... }`. Run::step()
 * catches it, turns it into a failed Step, and lets the scenario carry on or
 * stop as it chooses.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\StepFailed
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class StepFailed extends \RuntimeException
{
    /**
     * @param string $message what the registry, or this code, said went wrong
     * @param string $eppCode the EPP result code when there was one, e.g. '2303'
     */
    public function __construct(string $message, public readonly string $eppCode = '') {
        parent::__construct($message);
    }
}
