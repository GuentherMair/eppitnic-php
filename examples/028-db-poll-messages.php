<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';

use RedBeanPHP\R;

$nic = new Net_EPP_Client();

$results = R::getAll("SELECT * FROM messages WHERE archived_time IS NULL ORDER BY id DESC");
print_r($results);
