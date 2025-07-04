<?php

if ( ! defined('LOG_EMERG'))   define('LOG_EMERG',   0); /* system is unusable */
if ( ! defined('LOG_ALERT'))   define('LOG_ALERT',   1); /* action must be taken immediately */
if ( ! defined('LOG_CRIT'))    define('LOG_CRIT',    2); /* critical conditions */
if ( ! defined('LOG_ERR'))     define('LOG_ERR',     3); /* error conditions */
if ( ! defined('LOG_WARNING')) define('LOG_WARNING', 4); /* warning conditions */
if ( ! defined('LOG_NOTICE'))  define('LOG_NOTICE',  5); /* normal but significant condition */
if ( ! defined('LOG_INFO'))    define('LOG_INFO',    6); /* informational */
if ( ! defined('LOG_DEBUG'))   define('LOG_DEBUG',   7); /* debug-level messages */
