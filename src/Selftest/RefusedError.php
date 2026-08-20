<?php

namespace Eppitnic\Selftest;

/**
 * The self-test declined to run.
 *
 * Its own class so the CLI can answer with a distinct exit code: a refusal is
 * not a failed test, it is a test that never started, and a caller scripting
 * around it needs to tell those apart.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\RefusedError
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RefusedError extends \RuntimeException
{
}
