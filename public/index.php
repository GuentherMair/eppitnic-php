<?php

// Slim
use Eppitnic\Api\Middleware;
use Eppitnic\Api\SetupApp;
use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Slim\Factory\AppFactory;

// composer autoloading pulls in every self-contained helper (constants, DB
// connection) via composer.json's `files` list, and Eppitnic\*/Config classes
// lazily via PSR-4/classmap -- nothing else needs an explicit require before
// $app exists.
require dirname(__FILE__) . '/../vendor/autoload.php';

// Nothing below this point works without config/config.php: Config::get()
// on the very next line would throw Setup\ConfigMissing, and every route file
// this loop requires is free to touch the database at request time. Rather
// than let that be an uncaught fatal on every route including '/', an absent
// config.php serves the bundled installer instead -- see SetupApp and
// src/Api/Routes/setup.php.
if ( ! ConfigFile::exists()) {
    (new SetupApp())->run();
    return;
}

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
foreach (['root', 'network_check', 'users', 'user_settings', 'resellers', 'session', 'contact',
          'domain', 'tasks', 'cronjobs', 'smtp', 'remote_auth', 'trusted_proxies', 'whois', 'history'] as $routes) {
    require EPPITNIC_ROOT . "/src/Api/Routes/{$routes}.php";
}

// run application
try {
  $app->run();
} catch (\Throwable $e) {
  // \Throwable, not \Exception -- a \Throwable also catches \Error
  // subclasses (e.g. a TypeError) that a bare Exception catch would miss and
  // let escape as a blank response. Logged and answered in the same JSON
  // shape as every other error here, rather than print_r()'d straight to
  // whoever triggered it -- that used to dump the whole exception object
  // graph, including any embedded values, to the client.
  error_log((string) $e);
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Internal server error']);
}
