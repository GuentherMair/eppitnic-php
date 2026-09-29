<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `dnssec` setting: whether DS records are sent to the registry and the
 * secDNS extensions announced at login. Shared by `config dnssec` and the
 * admin-only `/v1/dnssec`.
 *
 * @category    Net
 * @package     Eppitnic\Service\DnssecSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DnssecSettings
{
    public static function active(): bool {
        return (int) (Config::get('dnssec')['active'] ?? 0) === 1;
    }

    /** persist the setting and record the change to `history` */
    public static function set(bool $active, int $userId): void {
        Config::set('dnssec', ['active' => $active ? 1 : 0]);
        History::record('dnssec', 0, 'update', ['active' => $active], $userId);
    }
}
