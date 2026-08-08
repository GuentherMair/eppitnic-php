<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';

$nic = new Net_EPP_Client();

// see "templates/" folder for variables

// hello
print_r($nic->sendRequest($nic->fetch("session-hello")));

// login
$nic->assign('username', $nic->EPPCfg->username);
$nic->assign('password', $nic->EPPCfg->password);
$nic->assign('lang', $nic->EPPCfg->lang);
$nic->assign('newPW', '');
$nic->assign('dnssec', (@isset($nic->EPPCfg->dnssec->active)) ? (int)$nic->EPPCfg->dnssec->active : 0);
print_r($nic->sendRequest($nic->fetch("session-login")));

// logout
$nic->assign('clTRID', $nic->set_clTRID());
print_r($nic->sendRequest($nic->fetch("session-logout")));
