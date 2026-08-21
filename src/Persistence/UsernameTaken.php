<?php

namespace Eppitnic\Persistence;

/**
 * $username is already in use. A distinct type rather than a message match,
 * so a caller (UserCreateCommand, Setup\Installer) can catch it precisely and
 * decide its own response -- a CLI exit code, an HTTP 400 -- without parsing
 * text.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\UsernameTaken
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class UsernameTaken extends \RuntimeException
{
}
