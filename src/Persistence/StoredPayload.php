<?php

namespace Eppitnic\Persistence;

/**
 * The two shapes an EPP body can have in `transactions`/`responses`/`msgqueue`:
 * plain, or 6.x's deprecated `__SERIALIZED:` envelope. Every read goes through
 * decode(), or base64 arrives where XML was expected and looks like corruption.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\StoredPayload
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class StoredPayload
{
    private const ENVELOPE = '__SERIALIZED:';

    /**
     * The column's content as text, whichever way it was stored.
     *
     * @param string $stored the raw column value
     * @return string|null null when the envelope is present but cannot be made
     *         sense of -- which is not the same as an empty column, and callers
     *         should not treat it as one
     */
    public static function decode(string $stored): ?string {
        if ( ! self::isWrapped($stored)) {
            return $stored;
        }

        $decoded = base64_decode(substr($stored, strlen(self::ENVELOPE)), true);
        if ($decoded === false) {
            return null;
        }

        $value = @unserialize($decoded);

        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            // the header map -- the only array shape 6.x wrapped
            return self::renderHeaders($value);
        }
        return null;
    }

    /**
     * A field => value map as the raw header block current code stores. No
     * status line and no re-casing: 6.x kept only the fields as captured, and
     * inventing either would be making up what the server said.
     *
     * @param array<string, mixed> $headers
     * @return string|null null if any field is not something a header line can
     *         hold, rather than a mangled block
     */
    private static function renderHeaders(array $headers): ?string {
        $lines = [];

        foreach ($headers as $name => $value) {
            // a repeated field is one line each, which is how it arrived
            foreach (is_array($value) ? $value : [$value] as $single) {
                if ( ! is_scalar($single)) {
                    return null;
                }
                $lines[] = $name . ': ' . $single;
            }
        }

        return $lines === [] ? '' : implode("\r\n", $lines) . "\r\n";
    }

    public static function isWrapped(string $stored): bool {
        return str_starts_with($stored, self::ENVELOPE);
    }
}
