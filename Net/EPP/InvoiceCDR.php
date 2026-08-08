<?php

require_once 'Net/EPP/InvoiceInterface.php';

/**
 * A CDR file storage class for invoicing.
 *
 * LICENSE:
 *
 * Copyright (c) Günther Mair <info@inet-services.it>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Net_EPP_InvoiceCDR
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Net_EPP_InvoiceCDR implements Net_EPP_InvoiceInterface
{
  protected $cdr       = "/var/log/eppitnic/billing.cdr";
  protected $delimiter = ";";
  protected $enclosure = '"';
  protected $eol       = "\n";
  protected $fh        = NULL;

  protected $storage   = NULL;
  protected $status    = NULL;
  protected $error     = "";
  protected $renewByPollQueueMsg;

  /**
   * Class constructor
   *
   *  - initialize database connection
   *
   * @access   public
   * @param    object                                 XML configuration object
   * @param    Net_EPP_StorageInterface               storage class
   * @param    boolean                                status (CDR file writeable)
   */
  function __construct($cfg, &$storage) {
    if (is_object($cfg->webinterface->cdr))       $this->cdr       = $cfg->webinterface->cdr;
    if (is_object($cfg->webinterface->delimiter)) $this->delimiter = $cfg->webinterface->delimiter;
    if (is_object($cfg->webinterface->enclosure)) $this->enclosure = $cfg->webinterface->enclosure;

    // set renew handler
    $this->renewByPollQueueMsg = (is_object($cfg->webinterface->renewByPollQueueMsg) && ($cfg->webinterface->renewByPollQueueMsg == 1)) ? TRUE : FALSE;

    if (is_object($cfg->webinterface->eol)) {
      switch (strtolower($cfg->webinterface->eol)) {
        case "dos":
          $this->eol = "\r\n";
          break;
        case "apple":
          $this->eol = "\r";
          break;
        case "unix":
        default:
          break;
      }
    }

    $this->storage = $storage;
    $this->status = is_writeable($this->cdr);
    if ( ! $this->status) {
      $this->setError('CDR file is not writeable.');
    }
    return $this->status;
  }

  /**
   * get a single variable/setting from class
   *
   * @access   public
   * @param    string  variable name
   * @return   mix     value of variable
   */
  public function get($var) {
    return $this->$var;
  }

  /**
   * set error code and message
   *
   * @access   protected
   * @param    string    error message
   */
  protected function setError($msg) {
    $this->error = $msg;
  }

  /**
   * get error message
   *
   * @access   public
   * @return   string    error message
   */
  public function getError() {
    return $this->error;
  }

  /**
   * store data to DB
   *
   * @access   public
   * @param    string    operation type
   * @param    string    billing_id (client reference number)
   * @param    string    object being invoiced
   * @param    string    date string / invoice period (optional - this defaults to a ISO 8601 date)
   * @return   boolean   status
   */
  public function doAccount($operation, $billing_id, $object, $date = NULL) {
    $tmp = $this->storage->doAccount($operation, $billing_id, $object, $date);
    if ( ! $tmp) {
      $this->setError($this->storage->getError());
    }
    return $tmp;
  }

  /**
   * account renewable domains found in DB
   *
   * @access   public
   * @return   int      number of invoiceable domains
   */
  public function doRenew() {
    // if we are renewing based on poll queue messages don't use this method (double invoicing!)
    if ($this->renewByPollQueueMsg) {
      return -1;
    }

    $domains = $this->storage->invoiceableDomains();
    if ($domains === FALSE) {
      return FALSE;
    }

    foreach ($domains as $domain) {
      $this->doAccount('renew', $domain['billing_id'], $domain['name']);
    }

    $this->storage->renewDomains();
    return count($domains);
  }

  /**
   * store data to DB
   *
   * @access   public
   * @return   boolean   status
   */
  public function doExport() {
    $this->fh = fopen($this->cdr, 'a');
    if ($this->fh === FALSE) {
      return FALSE;
    }

    $records = $this->storage->accountableServices();
    if ( $records === FALSE ) {
      return FALSE;
    }

    foreach ($records as $record) {
      $tmp = array();
      $tmp[] = $record['operation'];
      $tmp[] = $record['billing_id'];
      $tmp[] = $record['object'];
      $tmp[] = $record['date'];
      $tmp[] = $record['time'];
      $tmp[] = date("c");
      fwrite($this->fh, $this->enclosure . implode($this->enclosure.$this->delimiter.$this->enclosure, $tmp) . $this->enclosure . $this->eol);
    }
    $this->storage->closeAccountableServices($records);
    fclose($this->fh);

    return count($records);
  }
}
