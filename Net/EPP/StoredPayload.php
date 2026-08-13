<?php

namespace Net\EPP;

/**
 * The two shapes an EPP body can have in `transactions` / `responses` /
 * `msgqueue`.
 *
 * The 6.x codebase wrapped these columns as `__SERIALIZED:` +
 * base64(serialize($string)) -- a serialized *string*, so the envelope carried
 * no information the column did not already have, at a third again the size.
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
     * The body as it was sent or received, whichever way it was stored.
     *
     * @param string $stored the raw column value
     * @return string|null null when the envelope is present but damaged --
     *         which is not the same as an empty body, and callers should not
     *         treat it as one
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
        return is_string($value) ? $value : null;
    }

    public static function isWrapped(string $stored): bool {
        return str_starts_with($stored, self::ENVELOPE);
    }
}
