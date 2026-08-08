<?php

require_once dirname(__FILE__).'/InvoiceInterface.php';

use RedBeanPHP\R;

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

  protected $status    = NULL;
  protected $error     = "";

  /**
   * Class constructor
   *
   *  - checks that the CDR file is writeable (DB access is via RedBeanPHP's
   *    R:: facade, live once helpers/db.php has run -- no handle to inject)
   *
   * @access   public
   */
  function __construct() {
    $this->status = is_writeable($this->cdr);
    if ( ! $this->status) {
      $this->setError('CDR file is not writeable.');
    }
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
   * @param    string    date string / invoice period (optional - defaults to today via CURDATE())
   * @return   boolean   status
   */
  public function doAccount($operation, $billing_id, $object, $date = NULL) {
    if ($date === NULL) {
      R::exec("INSERT INTO accounting (operation, billing_id, object, date) VALUES (?, ?, ?, CURDATE())", [$operation, $billing_id, $object]);
    } else {
      R::exec("INSERT INTO accounting (operation, billing_id, object, date) VALUES (?, ?, ?, ?)", [$operation, $billing_id, $object, $date]);
    }
    return TRUE;
  }

  /**
   * account renewable domains found in DB (active, not invoiced in the last year)
   *
   * @access   public
   * @return   int      number of invoiceable domains
   */
  public function doRenew() {
    $domains = R::getAll("
      SELECT d.domain AS name, u.billing_id
      FROM domains d, users u
      WHERE d.active = 1 AND d.last_invoice < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND d.user_id = u.id");

    foreach ($domains as $domain) {
      $this->doAccount('renew', $domain['billing_id'], $domain['name']);
    }

    R::exec("UPDATE domains SET last_invoice = CURDATE() WHERE active = 1 AND last_invoice < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)");
    return count($domains);
  }

  /**
   * append open (status = 0) accounting rows to the CDR file, then mark them closed
   *
   * @access   public
   * @return   boolean|int   number of exported records, or FALSE if the CDR file isn't writeable
   */
  public function doExport() {
    $this->fh = fopen($this->cdr, 'a');
    if ($this->fh === FALSE) {
      return FALSE;
    }

    $records = R::getAll("SELECT id, operation, billing_id, object, date, time FROM accounting WHERE status = 0 ORDER BY id DESC");

    foreach ($records as $record) {
      $row = [
        $record['operation'],
        $record['billing_id'],
        $record['object'],
        $record['date'],
        $record['time'],
        date("c")
      ];
      fwrite($this->fh, $this->enclosure . implode($this->enclosure.$this->delimiter.$this->enclosure, $row) . $this->enclosure . $this->eol);
    }
    fclose($this->fh);

    if ( ! empty($records)) {
      $maxId = max(array_column($records, 'id'));
      R::exec("UPDATE accounting SET status = 1 WHERE id <= ?", [$maxId]);
    }

    return count($records);
  }
}
