<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);

function storeResponse($r) {
  global $nic;
  global $db;

  $xml = $nic->parseResponse($r['body']);

  if (@is_object($xml->response) && @is_object($xml->response->trID)) {
    $svTRID = (string)$xml->response->trID->svTRID;
    $resultCode = (string)$xml->response->result['code'];
  } else if (@is_object($xml->greeting)) {
    $svTRID = 'greeting';
    $resultCode = '';
  } else {
    $svTRID = 'undefined';
    $resultCode = '';
  }

  if (@is_object($xml->response->result->extValue->reason)) {
    $ns = $xml->getNamespaces(TRUE);
    $tmp = $xml->response->result->extValue->value->children($ns['extepp']);
    $extValueReasonCode = (string)$tmp->reasonCode;
    $extValueReason = (string)$xml->response->result->extValue->reason;
  } else {
    $extValueReasonCode = '';
    $extValueReason = '';
  }

  $db->storeResponse($nic->get_clTRID(), $svTRID, $resultCode, 0, $r, $extValueReasonCode, $extValueReason);
}

// hello
$data = $nic->fetch("hello");
$db->storeTransaction($nic->set_clTRID(), "hello", "", $data);
storeResponse($nic->sendRequest($data));

// login
$nic->assign('username', $nic->EPPCfg->username);
$nic->assign('password', $nic->EPPCfg->password);
$nic->assign('lang', $nic->EPPCfg->lang);
$nic->assign('newPW', '');
$nic->assign('dnssec', (@isset($nic->EPPCfg->dnssec)) ? (int)$nic->EPPCfg->dnssec : 0);

$data = $nic->fetch("login");
$db->storeTransaction($nic->set_clTRID(), "login", "", $data);
storeResponse($nic->sendRequest($data));

// logout
$nic->assign('clTRID', $nic->set_clTRID());

$data = $nic->fetch("logout");
$db->storeTransaction($nic->get_clTRID(), "logout", "", $data);
storeResponse($nic->sendRequest($data));
