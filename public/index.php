<?php

// Slim
use Net\EPP\Config;
use Net\EPP\Helpers;
use Slim\Factory\AppFactory;

// composer autoloading pulls in every self-contained helper (constants, DB
// connection) via composer.json's `files` list, and Net\EPP\*/Config classes
// lazily via PSR-4/classmap -- nothing else needs an explicit require before
// $app exists.
require dirname(__FILE__) . '/../vendor/autoload.php';

// get timing for later logging
$time_start = microtime(true);
$region = Config::get('region');
date_default_timezone_set($region['timezone']);
setlocale(LC_MONETARY, $region['lc_monetary']);
setlocale(LC_TIME, $region['lc_time']);

// initialize app
$app = AppFactory::create();

// body-parsing, trailing-slash normalization, error handling, CORS
Helpers::registerMiddleware($app);

// routes
require dirname(__FILE__) . '/../routes/root.php';
require dirname(__FILE__) . '/../routes/network_check.php';
require dirname(__FILE__) . '/../routes/users.php';
require dirname(__FILE__) . '/../routes/session.php';
require dirname(__FILE__) . '/../routes/contact.php';
require dirname(__FILE__) . '/../routes/domain.php';
require dirname(__FILE__) . '/../routes/reminders.php';
require dirname(__FILE__) . '/../routes/whois.php';
require dirname(__FILE__) . '/../routes/changelog.php';

// run application
try {
  $app->run();
} catch (Exception $e) {
  print_r($e);
}
