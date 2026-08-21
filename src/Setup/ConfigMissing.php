<?php

namespace Eppitnic\Setup;

/**
 * config/config.php does not exist, and the six DB_* constants were not
 * predefined in-process either -- there is nothing to connect with.
 *
 * A distinct type from a bare \RuntimeException so a caller can tell "not
 * installed yet" (point at `eppitnic setup`, or serve the bundled installer
 * page) from "installed but broken" (an unreachable database, a permissions
 * error) without parsing the message.
 *
 * @category    Net
 * @package     Eppitnic\Setup\ConfigMissing
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ConfigMissing extends \RuntimeException
{
}
