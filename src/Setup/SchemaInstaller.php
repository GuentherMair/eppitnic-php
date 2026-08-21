<?php

namespace Eppitnic\Setup;

use Eppitnic\Config;
use RedBeanPHP\R;

/**
 * Decides whether the connected database is empty, already running
 * eppitnic, or something else -- and, on empty, applies
 * config/mariadb-schema.sql.
 *
 * Nothing in PHP used to run that file at all: Config's own migration lookup
 * only globs mariadb-schema-upgrade-*.sql, which by construction cannot match
 * it, so an empty database fell into the schema-versioning code's legacy
 * '060700' baseline and failed on the 6.7-to-7.0 upgrade's first
 * ALTER TABLE. state() is what tells those two cases apart before that
 * migration chain ever runs.
 *
 * MariaDB is this application's only supported database, so this talks
 * `SHOW TABLES` directly -- the same call Config::migrate() already makes --
 * rather than routing through a portability layer this codebase has no other
 * use for.
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
                // next by Installer, walks its own upgrade chain via Config::migrate()
                return;
        }
    }
}
