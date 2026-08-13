<?php

namespace Net\EPP\Support;

/**
 * Request-shape checks, run before anything reaches the registry.
 *
 * Deliberately shallow: enough to reject obvious nonsense early and cheaply.
 * The registry's schemas are the authority on what is actually acceptable.
 *
 * @category    Net
 * @package     Net\EPP\Support\Validate
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Validate
{
    /**
     * contacts table column widths, config/mariadb-schema.sql
     */
    public const CONTACT_FIELD_MAX_LENGTHS = [
        'handle'          => 32,
        'name'            => 256,
        'org'             => 256,
        'street'          => 256,
        'street2'         => 128,
        'street3'         => 128,
        'city'            => 128,
        'province'        => 128,
        'postalcode'      => 16,
        'countrycode'     => 2,
        'voice'           => 64,
        'fax'             => 64,
        'email'           => 64,
        'authinfo'        => 64,
        'nationalitycode' => 2,
        'regcode'         => 32,
        'schoolcode'      => 32,
    ];

    /**
     * domains table column widths, config/mariadb-schema.sql
     */
    public const DOMAIN_FIELD_MAX_LENGTHS = [
        'domain'     => 255,
        'authinfo'   => 64,
        'registrant' => 32,
        'admin'      => 32,
    ];

    // -----------------------------------------------------------------
    // request validation
    // -----------------------------------------------------------------

    /**
     * @param array $params request parameters
     * @param array $fields required field names
     * @return string|null error message listing every missing field, or null if none are missing
     */
    public static function requireFields(array $params, array $fields): ?string {
        $missing = [];
        foreach ($fields as $field) {
            if (empty($params[$field])) {
                $missing[] = $field;
            }
        }
        if ( ! empty($missing)) {
            return 'required field(s) missing or empty: ' . implode(', ', $missing);
        }
        return null;
    }

    /**
     * @param array $params request parameters
     * @param array $maxLengths field => max character length
     * @return string|null error message for the first field exceeding its limit, or null if none do
     */
    public static function maxLength(array $params, array $maxLengths): ?string {
        foreach ($maxLengths as $field => $max) {
            if (isset($params[$field]) && is_string($params[$field]) && strlen($params[$field]) > $max) {
                return "{$field} exceeds the maximum length of {$max} characters";
            }
        }
        return null;
    }

    /**
     * @param string $email address to check
     * @return bool status
     */
    public static function isEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * basic .it domain name shape check -- not a full RFC-1035 validator,
     * just enough to reject obvious garbage before it reaches the registry
     */
    public static function isDomain(string $domain): bool {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.it$/i', $domain);
    }
}
