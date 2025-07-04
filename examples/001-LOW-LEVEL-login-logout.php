<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';

$nic = new Net_EPP_Client();

// see "templates/" folder for variables

// hello
print_r($nic->sendRequest($nic->fetch("hello")));

// login
$nic->assign('username', $nic->EPPCfg->username);
$nic->assign('password', $nic->EPPCfg->password);
$nic->assign('lang', $nic->EPPCfg->lang);
$nic->assign('newPW', '');
$nic->assign('dnssec', (@isset($nic->EPPCfg->dnssec)) ? (int)$nic->EPPCfg->dnssec : 0);
print_r($nic->sendRequest($nic->fetch("login")));

// logout
$nic->assign('clTRID', $nic->set_clTRID());
print_r($nic->sendRequest($nic->fetch("logout")));
