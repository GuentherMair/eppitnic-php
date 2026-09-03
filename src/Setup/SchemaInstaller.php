<?php

namespace Eppitnic\Setup;

use Eppitnic\Config;
use RedBeanPHP\R;

/**
 * Decides whether the database is empty, already eppitnic, or something else,
 * and applies config/mariadb-schema.sql when empty -- which nothing did before,
 * so an empty one fell into the '060700' baseline and failed on its first
 * ALTER.
 *
 * @category    Net
 * @package     Eppitnic\Setup\SchemaInstaller
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SchemaInstaller
{
    public const EMPTY_DATABASE = 'empty';
    public const EPPITNIC       = 'eppitnic';
    public const UNRELATED      = 'unrelated';

    /**
     * @return string one of the three class constants above
     */
    public static function state(): string {
        $tables = array_map(static fn(array $row) => array_values($row)[0], R::getAll('SHOW TABLES'));
        if ($tables === []) {
            return self::EMPTY_DATABASE;
        }
        return in_array('settings', $tables, true) ? self::EPPITNIC : self::UNRELATED;
    }

    /**
     * @throws \RuntimeException if the database has tables but none named
     *         `settings` -- refused rather than run through Config::migrate(),
     *         which would otherwise mistake it for the pre-versioning legacy
     *         baseline and try the 6.7-to-7.0 upgrade against it
     */
    public static function install(): void {
        switch (self::state()) {
            case self::EMPTY_DATABASE:
                Config::runSqlFile(EPPITNIC_ROOT . '/config/mariadb-schema.sql');
                return;
            case self::UNRELATED:
                throw new \RuntimeException(
                    'This database has tables but none named `settings` -- it does not look like ' .
                    'an empty database or an existing eppitnic installation. Point setup at an empty ' .
                    'database, or one already running eppitnic.'
                );
            default: // self::EPPITNIC
                // a `settings` table already exists -- Config::init(), called
                // next by Installer, walks its own upgrade chain via
                // Config::migrate()
                return;
        }
    }
}
