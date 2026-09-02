<?php

namespace Eppitnic\Selftest;

/**
 * The self-test declined to run. Its own class so the CLI answers with a
 * distinct exit code: a refusal is not a failed test but one that never
 * started, and a script around it has to tell those apart.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\RefusedError
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class RefusedError extends \RuntimeException
{
}
