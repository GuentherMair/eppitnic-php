<?php

namespace Eppitnic\Persistence;

/**
 * The array columns `domains` and `contacts` keep serialized -- ns, tech,
 * status, dnssec. 6.x base64'd them behind a `__SERIALIZED:` marker and
 * current code does not, so one table holds both shapes at once.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\SerializedColumn
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SerializedColumn
{
    /** the same 6.x marker StoredPayload strips off text payloads */
    private const ENVELOPE = '__SERIALIZED:';

    /**
     * @param string|null $stored the raw column value, either shape
     * @return array the decoded array, or [] when the column is empty or
     *         undecodable. Never false: callers index into these, and a bare
     *         unserialize() answering false on a wrapped value is the bug this
     *         exists to stop.
     */
    public static function toArray(?string $stored): array {
        if ($stored === null || $stored === '') {
            return [];
        }

        if (str_starts_with($stored, self::ENVELOPE)) {
            $stored = base64_decode(substr($stored, strlen(self::ENVELOPE)), true);
            if ($stored === false) {
                return [];
            }
        }

        $value = @unserialize($stored);
        return is_array($value) ? $value : [];
    }
}
