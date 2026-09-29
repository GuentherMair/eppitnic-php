<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The `debugfile` setting: where every registry exchange is logged, masked
 * by Transport\Curl::mask(). Shared by `config debugfile` and
 * `/v1/debugfile`.
 *
 * Only a `.log` file inside the var directory is accepted: the log holds
 * text users typed (contact names, ...), so a path into the web root or onto
 * config.php would let that text run as PHP.
 *
 * @category    Net
 * @package     Eppitnic\Service\DebugFile
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DebugFile
{
    /** what GET /v1/debugfile/content returns of a larger file: its end */
    public const TAIL_BYTES = 2 * 1024 * 1024;

    public const WARNING = 'The debug log records every registry exchange: commands, contact personal data '
        . 'and responses. Passwords, auth codes and session cookies are masked, but treat the file as '
        . 'confidential, keep it on only while debugging, and delete it afterwards.';

    /** EPPITNIC_VAR_DIR, else the checkout's var/ */
    public static function directory(): string {
        $dir = getenv('EPPITNIC_VAR_DIR');
        return rtrim($dir !== false && $dir !== '' ? $dir : EPPITNIC_ROOT . '/var', '/');
    }

    /** @return string the configured path, '' when logging is off */
    public static function get(): string {
        return (string) (Config::all()['debugfile'] ?? '');
    }

    /**
     * @return array{path: string, directory: string, exists: bool, size: int, writable: bool}
     *         `writable` false with a path set means nothing is being logged
     */
    public static function status(): array {
        $path = self::get();
        $exists = $path !== '' && is_file($path);
        return [
            'path'      => $path,
            'directory' => self::directory(),
            'exists'    => $exists,
            'size'      => $exists ? (int) filesize($path) : 0,
            'writable'  => $path !== '' && ($exists ? is_writable($path) : is_writable(dirname($path))),
        ];
    }

    /**
     * A file name, or an absolute path, as the path to store.
     *
     * @throws \InvalidArgumentException outside the var directory, or not a
     *         plain `.log` file name
     */
    public static function resolve(string $value): string {
        $value = trim($value);
        $directory = self::directory();
        $path = str_contains($value, '/') ? $value : $directory . '/' . $value;
        $name = basename($path);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.log$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "'{$name}' is not a log file name -- letters, digits, '.', '_' and '-', ending in .log"
            );
        }
        $real = realpath(dirname($path));
        $base = realpath($directory);
        if ($real === false || $base === false || ($real !== $base && ! str_starts_with($real, $base . '/'))) {
            throw new \InvalidArgumentException("the debug log must be inside {$directory}");
        }
        return $real . '/' . $name;
    }

    /**
     * Turn logging on, and record the change. The file is created 0600 before
     * anything is stored, so a path that cannot be written leaves the setting
     * as it was. Logging stops only through delete().
     *
     * @return array see status()
     * @throws \InvalidArgumentException see resolve(), or if the file cannot
     *         be written
     * @throws \DomainException while another log is recording
     */
    public static function set(string $value, int $userId): array {
        $path = self::resolve($value);
        $current = self::get();
        if ($current === $path) {
            return self::status();
        }
        if ($current !== '') {
            throw new \DomainException("the debug log is recording to {$current} -- delete it first");
        }

        self::touch($path);

        Config::set('debugfile', $path);
        History::record('debugfile', 0, 'update', ['debugfile' => $path], $userId);

        return self::status();
    }

    /**
     * Delete the log file, then clear the setting -- in that order, so a file
     * that cannot be deleted keeps both. A file already gone only clears it.
     *
     * @return array see status()
     * @throws \RuntimeException if the file exists and cannot be deleted
     */
    public static function delete(int $userId): array {
        $path = self::get();
        if ($path === '') {
            return self::status();
        }

        // is_link() too: unlink() removes a link itself, never its target
        if ((file_exists($path) || is_link($path)) && ! @unlink($path)) {
            throw new \RuntimeException("cannot delete '{$path}' -- the debug log is still set");
        }

        Config::set('debugfile', '');
        History::record('debugfile', 0, 'delete', ['debugfile' => $path], $userId);

        return self::status();
    }

    /**
     * The log's end, at most TAIL_BYTES.
     *
     * @return array{content: string, size: int, truncated: bool}|null null
     *         when logging is off or the file is missing or empty
     */
    public static function tail(): ?array {
        $status = self::status();
        if ( ! $status['exists'] || $status['size'] === 0) {
            return null;
        }

        $handle = @fopen($status['path'], 'r');
        if ($handle === false) {
            return null;
        }
        $truncated = $status['size'] > self::TAIL_BYTES;
        if ($truncated) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return ['content' => $content, 'size' => $status['size'], 'truncated' => $truncated];
    }

    /** @throws \InvalidArgumentException if $path cannot be opened for appending */
    private static function touch(string $path): void {
        $new = ! file_exists($path);
        $umask = umask(0077);
        $handle = @fopen($path, 'a');
        umask($umask);

        if ($handle === false) {
            throw new \InvalidArgumentException("cannot write '{$path}' -- check the directory's owner and mode");
        }
        fclose($handle);
        if ($new) {
            @chmod($path, 0600);
        }
    }
}
