<?php

Class runme
{
  public $xmlResult;

  public function example($num) {
    $this->xmlResult = @simplexml_load_string(file_get_contents(dirname(realpath(__FILE__)).'/example'.$num.'.xml'));

    $ns = $this->xmlResult->getNamespaces(TRUE);

    // passwdReminder
    if ( @is_object($this->xmlResult->response->extension->children($ns['extepp'])->passwdReminder->exDate) ) {
      $exDate = (string)$this->xmlResult->response->extension->children($ns['extepp'])->passwdReminder->exDate;
      return array(
        'type'   => 'passwdReminder',
        'domain' => '',
        'data'   => $exDate,
      );
    }

    // simpleMsgData
    if ( @is_object($this->xmlResult->response->extension->children($ns['extdom'])->simpleMsgData->name) ) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->simpleMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      return array(
        'type'   => 'simpleMsgData',
        'domain' => $domain,
        'data'   => $title,
      );
    }

    // dnsErrorMsgData
    if ( @is_object($this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain) ) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain->attributes()->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      foreach ($this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain->test as $child)
        $msg[] = $child->attributes()->name . ": " . $child->attributes()->status;
      return array(
        'type'   => 'dnsErrorMsgData',
        'domain' => $domain,
        'data'   => $title . " (" . implode(" / ", $msg) . ")",
      );
    }

    // chgStatusMsgData
    if ( @is_object($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->name) ) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      if ( @is_object($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus) ) {
        foreach ($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus->children($ns['domain'])->status as $child)
          $msg[] = $child->attributes()->s;
        foreach ($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus->children($ns['rgp'])->rgpStatus as $child)
          $msg[] = $child->attributes()->s;
      }
      return array(
        'type'   => 'chgStatusMsgData',
        'domain' => $domain,
        'data'   => $title . " (" . implode(" / ", $msg) . ")",
      );
    }

    // dlgMsgData
    if ( @is_object($this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->name) ) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      foreach ($this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->ns as $child)
        $msg[] = (string)$child;
      return array(
        'type'   => 'dlgMsgData',
        'domain' => $domain,
        'data'   => $title . " (" . implode(" / ", $msg) . ")",
      );
    }

    // domain transfers
    if ( @is_object($this->xmlResult->response->resData->children($ns['domain'])->trnData->name) ) {
      $domain = (string)$this->xmlResult->response->resData->children($ns['domain'])->trnData->name;
      $type = $this->xmlResult->response->resData->children($ns['domain'])->trnData->trStatus . "Transfer";
      $title = (string)$this->xmlResult->response->msgQ->msg . ": " .
        $this->xmlResult->response->resData->children($ns['domain'])->trnData->reID .
        " (" . $this->xmlResult->response->resData->children($ns['domain'])->trnData->reDate . ") => " .
        $this->xmlResult->response->resData->children($ns['domain'])->trnData->acID .
        " (" . $this->xmlResult->response->resData->children($ns['domain'])->trnData->acDate . ")";
      return array(
        'type'   => $type,
        'domain' => $domain,
        'data'   => $title,
      );
    }

    // unknown type
    return array(
      'type'   => 'unknown',
      'domain' => '',
      'data'   => (string)$this->xmlResult->response->msgQ->msg,
    );

  }

}

$x = new runme();
for ( $i = 0; $i < 10; $i++ )
  print_r($x->example(rand(0, 10)));

