<?php

namespace Eppitnic\Selftest;

/**
 * One self-contained sequence of registry operations.
 *
 * Split by lifecycle rather than by object type, because what a scenario can
 * clean up after itself is the thing that actually differs: a contact never
 * attached to a domain can be deleted at the end of the run, and one that was
 * attached cannot be deleted for another week.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Scenario
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
interface Scenario
{
    /**
     * @return string shown as the heading above this scenario's steps
     */
    public function name(): string;

    /**
     * Run it. Records its own steps; returns nothing, because a scenario that
     * failed halfway is described by its steps and not by a verdict.
     */
    public function execute(Run $run): void;
}
