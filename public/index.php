<?php

// Slim
use Slim\Factory\AppFactory;

// JWT
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// configuration
require __DIR__ . '/../helpers/config.php';

// get timing for later logging
$time_start = microtime(true);
$region = getConfig('region');
date_default_timezone_set($region['timezone']);
setlocale(LC_MONETARY, $region['lc_monetary']);
setlocale(LC_TIME, $region['lc_time']);

// autoload composer-based components (Slim and JWT)
require __DIR__ . '/../vendor/autoload.php';

// initialize app
$app = AppFactory::create();

// helpers
require __DIR__ . '/../helpers/middleware.php'; // CORS resides here
require __DIR__ . '/../helpers/jwt.php';
require __DIR__ . '/../helpers/totp.php';
require __DIR__ . '/../helpers/network.php';
require __DIR__ . '/../helpers/csv.php';
require __DIR__ . '/../helpers/changelog.php';

// routes
require __DIR__ . '/../routes/root.php';
require __DIR__ . '/../routes/network_check.php';
require __DIR__ . '/../routes/users.php';
require __DIR__ . '/../routes/session.php';
require __DIR__ . '/../routes/contact.php';
require __DIR__ . '/../routes/domain.php';
require __DIR__ . '/../routes/changelog.php';

// run application
try {
  $app->run();
} catch (Exception $e) {
  print_r($e);
}
