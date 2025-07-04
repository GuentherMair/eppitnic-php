<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);

$clTRID = $nic->get_clTRID();

// hello
$data = $nic->fetch("hello");
$db->storeTransaction($clTRID, "hello", "", $data);
$result = $nic->sendRequest($data);
$xml = $nic->parseResponse($result[body]);
$db->storeResponse($clTRID, $xml->response->trID->svTRID, $xml->response->result['code'], 0, $result);

// login
$nic->assign('username', $nic->EPPCfg->username);
$nic->assign('password', $nic->EPPCfg->password);
$nic->assign('lang', $nic->EPPCfg->lang);

$data = $nic->fetch("login");
$db->storeTransaction($clTRID, "login", "", $data);
$result = $nic->sendRequest($data);
$xml = $nic->parseResponse($result[body]);
$db->storeResponse($clTRID, $xml->response->trID->svTRID, $xml->response->result['code'], 0, $result);

// logout
$nic->assign('clTRID', $clTRID);

$data = $nic->fetch("logout");
$db->storeTransaction($clTRID, "logout", "", $data);
$result = $nic->sendRequest($data);
$xml = $nic->parseResponse($result[body]);
$db->storeResponse($clTRID, $xml->response->trID->svTRID, $xml->response->result['code'], 0, $result);

