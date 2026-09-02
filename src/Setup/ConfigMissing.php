<?php

namespace Eppitnic\Setup;

/**
 * Neither config/config.php nor the six DB_* constants exist, so there is
 * nothing to connect with. Its own type so a caller can tell "not installed
 * yet" from "installed but broken" without parsing a message.
 *
 * @category    Net
 * @package     Eppitnic\Setup\ConfigMissing
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ConfigMissing extends \RuntimeException
{
}
