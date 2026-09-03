<?php

namespace Eppitnic\Support;

/**
 * Request-shape checks, run before anything reaches the registry.
 *
 * Deliberately shallow: enough to reject obvious nonsense early and cheaply.
 * The registry's schemas are the authority on what is actually acceptable.
 *
 * @category    Net
 * @package     Eppitnic\Support\Validate
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
     * @return string|null error message listing every missing field, or null if
     *                     none are missing
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
     * @return string|null error message for the first field exceeding its
     *                     limit, or null if none do
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

    // -----------------------------------------------------------------
    // `epp` setting fields
    // -----------------------------------------------------------------

    /**
     * The registry's own schema rules, so they hold wherever a value is
     * accepted -- first-run setup and both `config epp-*` verbs validate here.
     * In the CLI alone, the web installer could seed what the CLI refused.
     *
     * @param string $field one of the FIELD_VALIDATORS keys
     * @return string|null a validation error, or null if $value is acceptable
     */
    public static function eppField(string $field, string $value): ?string {
        if (trim($value) !== $value || preg_match('/\s/', $value) === 1) {
            return "{$field} must not contain whitespace";
        }

        return match ($field) {
            'interface' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                ? 'interface must be an IPv4 address'
                : null,

            'lang' => in_array($value, ['it', 'en'], true)
                ? null
                : "lang must be 'it' or 'en'",

            // set_clTRID() appends 17 characters, and epp:trIDStringType caps
            // the whole clTRID at 64, so the prefix must leave room
            'cl_trid_prefix' => ($value !== '' && strlen($value) <= 47)
                ? null
                : 'cl_trid_prefix must be 1 to 47 characters',

            // eppcom:clIDType: 3 to 16 characters. '-REG' is nic.it's account
            // convention rather than a schema rule, but every real account has
            // it, so one without is almost certainly a mistake
            'username' => match (true) {
                strlen($value) < 3, strlen($value) > 16 =>
                    'username must be 3 to 16 characters (EPP clIDType)',
                ! str_ends_with($value, '-REG') =>
                    "username must end in '-REG' (nic.it's registrar account convention)",
                default => null,
            },

            // epp:pwType: 6 to 16 characters, for <pw> and <newPW> alike. The
            // registry's ceiling, not PasswordPolicy, which governs local
            // admin passwords only
            'password' => (strlen($value) < 6 || strlen($value) > 16)
                ? 'password must be 6 to 16 characters (EPP pwType)'
                : null,

            default => null,
        };
    }
}
