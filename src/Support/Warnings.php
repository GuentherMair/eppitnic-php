<?php

namespace Eppitnic\Support;

/**
 * Messages for a response that succeeded with a caveat: the registry took the
 * change, so it is still a 200, but something after it did not. Each is
 * logged too, since a caller may well ignore it.
 *
 * @category    Net
 * @package     Eppitnic\Support\Warnings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Warnings
{
    /**
     * @param string $what e.g. "domain 'example.it'"
     * @param string $error the store's own error text
     */
    public static function localWrite(string $what, string $error): string {
        $message = "Changed at the registry, but the local copy of {$what} could not be updated"
            . ($error !== '' ? " ({$error})" : '') . '; a later sync reconciles it';
        error_log("eppitnic: {$message}");
        return $message;
    }

    /**
     * @param string[] $warnings
     * @return array{warnings?: string[]} merged into a response body, so one
     *         without warnings keeps its usual shape
     */
    public static function field(array $warnings): array {
        return $warnings === [] ? [] : ['warnings' => array_values($warnings)];
    }
}
