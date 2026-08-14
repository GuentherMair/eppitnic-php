<?php

// Slim
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Slim\Factory\AppFactory;

// composer autoloading pulls in every self-contained helper (constants, DB
// connection) via composer.json's `files` list, and Eppitnic\*/Config classes
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
Middleware::register($app);

// routes
// Route files register closures on $app rather than declaring classes, so
// they are required rather than autoloaded -- the order is the routing order.
foreach (['root', 'network_check', 'users', 'session', 'contact',
          'domain', 'reminders', 'whois', 'history'] as $routes) {
    require EPPITNIC_ROOT . "/src/Api/Routes/{$routes}.php";
}

// run application
try {
  $app->run();
} catch (Exception $e) {
  print_r($e);
}
