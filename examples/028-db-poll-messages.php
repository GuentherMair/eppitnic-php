<?php

use Net\EPP\Client;

require_once dirname(__FILE__).'/../vendor/autoload.php';

use RedBeanPHP\R;

$nic = new Client();

$results = R::getAll("SELECT * FROM messages WHERE archived_time IS NULL ORDER BY id DESC");
print_r($results);
