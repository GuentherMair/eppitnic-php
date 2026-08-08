<?php

// RedBean
use \RedBeanPHP\R;

// Slim
use Slim\Factory\AppFactory;

// JWT
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// configuration
require dirname(__FILE__) . '/../helpers/config.php';

// get timing for later logging
$time_start = microtime(true);
$region = getConfig('region');
date_default_timezone_set($region['timezone']);
setlocale(LC_MONETARY, $region['lc_monetary']);
setlocale(LC_TIME, $region['lc_time']);

// autoload composer-based components (Slim and JWT)
require dirname(__FILE__) . '/../vendor/autoload.php';

// set up database connection
require dirname(__FILE__) . '/../helpers/db.php';

// initialize app
$app = AppFactory::create();

// helpers
require dirname(__FILE__) . '/../helpers/middleware.php'; // CORS resides here
require dirname(__FILE__) . '/../helpers/jwt.php';
require dirname(__FILE__) . '/../helpers/totp.php';
require dirname(__FILE__) . '/../helpers/network.php';
require dirname(__FILE__) . '/../helpers/csv.php';
require dirname(__FILE__) . '/../helpers/changelog.php';
require dirname(__FILE__) . '/../helpers/epp.php';
require dirname(__FILE__) . '/../helpers/contact.php';
require dirname(__FILE__) . '/../helpers/validate.php';

// routes
require dirname(__FILE__) . '/../routes/root.php';
require dirname(__FILE__) . '/../routes/network_check.php';
require dirname(__FILE__) . '/../routes/users.php';
require dirname(__FILE__) . '/../routes/session.php';
require dirname(__FILE__) . '/../routes/contact.php';
require dirname(__FILE__) . '/../routes/domain.php';
require dirname(__FILE__) . '/../routes/accounting.php';
require dirname(__FILE__) . '/../routes/reminders.php';
require dirname(__FILE__) . '/../routes/whois.php';
require dirname(__FILE__) . '/../routes/changelog.php';

// run application
try {
  $app->run();
} catch (Exception $e) {
  print_r($e);
}
