<?php

namespace Net\EPP;

/**
 * The two shapes an EPP body can have in `transactions` / `responses` /
 * `msgqueue`.
 *
 * The 6.x codebase wrapped these columns as `__SERIALIZED:` +
 * base64(serialize(...)). For a body that meant a serialized *string*, so the
 * envelope carried nothing the column did not already have, at a third again
 * the size. For `sv_httpheaders` it meant a serialized *array*, the response
 * headers as a field => value map, where current code stores the raw header
 * block the server sent.
 *
 * That envelope is **deprecated**: nothing writes it, the affected columns are
 * marked in the schema, and `eppitnic doctor normalize-payloads` strips it from
 * rows that still have it. Reading stays permanent, though -- an installation
 * that never runs the cleanup must keep working -- so every read goes through
 * decode() rather than assuming either shape. A reader that assumes gets base64
 * where it expected XML, which parses as nothing and looks like a corrupt
 * response rather than a decoding mistake.
 *
 * @category    Net
 * @package     Net\EPP\StoredPayload
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
     * A field => value map as the raw header block current code stores.
     *
     * No status line: 6.x kept only the fields, so there is none to render and
     * inventing one would be making up what the server said. Names keep the
     * lower case they were captured in -- HTTP field names are case-insensitive,
     * and re-casing them would be the same kind of invention.
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
