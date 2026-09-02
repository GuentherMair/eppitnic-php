<?php

namespace Eppitnic\Setup;

/**
 * The only thing that reads, writes or locates config/config.php, whose
 * existence is the "installed" flag -- kept trustworthy by write()'s
 * refuse-if-exists guard. One owner, so the redirect seam has somewhere to live.
 *
 * @category    Net
 * @package     Eppitnic\Setup\ConfigFile
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ConfigFile
{
    private static ?string $path = null;

    /**
     * Where config.php lives: the test seam if set, else EPPITNIC_CONFIG_DIR --
     * a container's per-install directory, outside config/ so it never shadows
     * constants.php or the schema files -- else the checkout's own config/.
     */
    public static function path(): string {
        if (self::$path !== null) {
            return self::$path;
        }
        $dir = getenv('EPPITNIC_CONFIG_DIR');
        return ($dir !== false && $dir !== '' ? $dir : EPPITNIC_ROOT . '/config') . '/config.php';
    }

    /**
     * Point at a throwaway file instead of config/config.php. Test suite
     * only -- pass null to restore the real path.
     */
    public static function usePath(?string $path): void {
        self::$path = $path;
    }

    public static function exists(): bool {
        return is_readable(self::path());
    }

    /**
     * @throws \RuntimeException if the file already exists, the containing
     *         directory isn't writable, or the write/chmod fails
     */
    public static function write(DatabaseCredentials $creds): void {
        if (self::exists()) {
            throw new \RuntimeException("'" . self::path() . "' already exists -- refusing to overwrite it.");
        }

        $dir = dirname(self::path());
        if ( ! is_writable($dir)) {
            throw new \RuntimeException("Cannot write '" . self::path() . "': '{$dir}' is not writable.");
        }

        $d = $creds->toDefines();
        $php = "<?php\n\n"
            . "define('DB_TYPE',     " . var_export($d['DB_TYPE'], true) . ");\n"
            . "define('DB_HOST',     " . var_export($d['DB_HOST'], true) . ");\n"
            . "define('DB_NAME',     " . var_export($d['DB_NAME'], true) . ");\n"
            . "define('DB_CHARSET',  " . var_export($d['DB_CHARSET'], true) . ");\n"
            . "define('DB_USER',     " . var_export($d['DB_USER'], true) . ");\n"
            . "define('DB_PASSWORD', " . var_export($d['DB_PASSWORD'], true) . ");\n";

        if (file_put_contents(self::path(), $php) === false) {
            throw new \RuntimeException("Unable to write '" . self::path() . "'.");
        }
        // holds a live database password
        if ( ! @chmod(self::path(), 0600)) {
            throw new \RuntimeException("Wrote '" . self::path() . "' but could not chmod it to 0600.");
        }
    }
}
