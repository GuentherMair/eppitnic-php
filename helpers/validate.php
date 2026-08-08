<?php

/**
 * lightweight request validation helpers -- return an error message string
 * on the first problem found, or null when everything checked out. Kept
 * deliberately small: required-field presence, DB-column-width limits (so
 * a too-long value fails with a clear 400 instead of a silent truncation
 * or a raw SQL error), and a couple of format checks for the fields most
 * likely to be garbage-in from a client.
 */

function requireFields(array $params, array $fields): ?string {
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
 * @param array $params
 * @param array $maxLengths  field => max character length
 */
function maxLength(array $params, array $maxLengths): ?string {
    foreach ($maxLengths as $field => $max) {
        if (isset($params[$field]) && is_string($params[$field]) && strlen($params[$field]) > $max) {
            return "{$field} exceeds the maximum length of {$max} characters";
        }
    }
    return null;
}

function isValidEmailFormat(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * basic .it domain name shape check -- not a full RFC-1035 validator,
 * just enough to reject obvious garbage before it reaches the registry
 */
function isValidDomainFormat(string $domain): bool {
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.it$/i', $domain);
}

/**
 * contacts table column widths, config/mariadb-schema.sql
 */
const CONTACT_FIELD_MAX_LENGTHS = [
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
const DOMAIN_FIELD_MAX_LENGTHS = [
    'domain'     => 255,
    'authinfo'   => 64,
    'registrant' => 32,
    'admin'      => 32,
];
