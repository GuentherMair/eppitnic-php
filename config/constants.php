<?php

// current DB schema version this codebase expects -- compared against the
// `settings` table's 'schema_version' row by Config's auto-migration step
// (Net/EPP/Config.php). Zero-padded MMmmrr (2-digit major/minor/release,
// e.g. 7.0.0 -> '070000', 7.1.2 -> '070102') rather than a dotted string --
// fixed-width so plain string/int comparison sorts correctly (a dotted
// "7.10" would otherwise sort before "7.2"). Bump this and drop a matching
// config/mariadb-schema-upgrade-{old}-to-{new}.sql file when adding a
// migration; each file bridges exactly one version to the next one in the
// chain -- Config applies them iteratively, it never jumps versions in one file.
if ( ! defined('SCHEMA_VERSION')) define('SCHEMA_VERSION', '070000');

// log severities -- Net/EPP/AbstractObject.php's $debug property compares against these
if ( ! defined('LOG_EMERG'))   define('LOG_EMERG',   0); /* system is unusable */
if ( ! defined('LOG_ALERT'))   define('LOG_ALERT',   1); /* action must be taken immediately */
if ( ! defined('LOG_CRIT'))    define('LOG_CRIT',    2); /* critical conditions */
if ( ! defined('LOG_ERR'))     define('LOG_ERR',     3); /* error conditions */
if ( ! defined('LOG_WARNING')) define('LOG_WARNING', 4); /* warning conditions */
if ( ! defined('LOG_NOTICE'))  define('LOG_NOTICE',  5); /* normal but significant condition */
if ( ! defined('LOG_INFO'))    define('LOG_INFO',    6); /* informational */
if ( ! defined('LOG_DEBUG'))   define('LOG_DEBUG',   7); /* debug-level messages */

// generic script exit codes (1-9), for use by CLI scripts / examples
if ( ! defined('SYNTAX_ERROR'))      define('SYNTAX_ERROR', 1);       // wrong/missing CLI arguments
if ( ! defined('FILE_NOT_READABLE')) define('FILE_NOT_READABLE', 2);  // input file/CSV unreadable
if ( ! defined('INVALID_INPUT'))     define('INVALID_INPUT', 3);      // eg. no valid .it domain given
if ( ! defined('CONFIG_ERROR'))      define('CONFIG_ERROR', 4);       // config/config.php missing/incomplete, or DB unreachable
if ( ! defined('OUTPUT_ERROR'))      define('OUTPUT_ERROR', 5);       // unable to write an output file

// session script exit codes (10-19), for use by CLI scripts / examples
if ( ! defined('HELLO_FAILED'))           define('HELLO_FAILED', 10);
if ( ! defined('LOGIN_FAILED'))           define('LOGIN_FAILED', 11);
if ( ! defined('LOGOUT_FAILED'))          define('LOGOUT_FAILED', 12);
if ( ! defined('POLL_FAILED'))            define('POLL_FAILED', 13);
if ( ! defined('CHANGE_PASSWORD_FAILED')) define('CHANGE_PASSWORD_FAILED', 14);

// domain script exit codes (20-29), for use by CLI scripts / examples
if ( ! defined('DOMAIN_CREATE_FAILED'))   define('DOMAIN_CREATE_FAILED', 20);
if ( ! defined('DOMAIN_FETCH_FAILED'))    define('DOMAIN_FETCH_FAILED', 21);
if ( ! defined('DOMAIN_UPDATE_FAILED'))   define('DOMAIN_UPDATE_FAILED', 22);
if ( ! defined('DOMAIN_DELETE_FAILED'))   define('DOMAIN_DELETE_FAILED', 23);
if ( ! defined('DOMAIN_STORE_FAILED'))    define('DOMAIN_STORE_FAILED', 24);
if ( ! defined('DOMAIN_CHECK_FAILED'))    define('DOMAIN_CHECK_FAILED', 25);
if ( ! defined('DOMAIN_RESTORE_FAILED'))  define('DOMAIN_RESTORE_FAILED', 26);
if ( ! defined('DOMAIN_TRANSFER_FAILED')) define('DOMAIN_TRANSFER_FAILED', 27);
if ( ! defined('DOMAIN_EXPORT_FAILED'))   define('DOMAIN_EXPORT_FAILED', 28);
if ( ! defined('DOMAIN_IMPORT_FAILED'))   define('DOMAIN_IMPORT_FAILED', 29);

// contact script exit codes (30-39), for use by CLI scripts / examples
if ( ! defined('CONTACT_CREATE_FAILED')) define('CONTACT_CREATE_FAILED', 30);
if ( ! defined('CONTACT_FETCH_FAILED'))  define('CONTACT_FETCH_FAILED', 31);
if ( ! defined('CONTACT_UPDATE_FAILED')) define('CONTACT_UPDATE_FAILED', 32);
if ( ! defined('CONTACT_DELETE_FAILED')) define('CONTACT_DELETE_FAILED', 33);
if ( ! defined('CONTACT_STORE_FAILED'))  define('CONTACT_STORE_FAILED', 34);
if ( ! defined('CONTACT_CHECK_FAILED'))  define('CONTACT_CHECK_FAILED', 35);
