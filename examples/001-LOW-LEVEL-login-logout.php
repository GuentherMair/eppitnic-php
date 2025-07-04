<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';

$nic = new Net_EPP_IT_Client();

// see "templates/" folder for variables

// hello
print_r( $nic->sendRequest($nic->fetch("hello")) );

// login
$nic->assign('username', $nic->EPPCfg->username);
$nic->assign('password', $nic->EPPCfg->password);
$nic->assign('lang', $nic->EPPCfg->lang);
print_r( $nic->sendRequest($nic->fetch("login")) );

// logout
$nic->assign('clTRID', $nic->set_clTRID());
print_r( $nic->sendRequest($nic->fetch("logout")) );

