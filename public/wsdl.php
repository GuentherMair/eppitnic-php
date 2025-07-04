<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'libs/nusoap/nusoap.php';
require_once 'Net/EPP/IT/WSDL.php';

/**
 * This file provides a WSDL interface to the EPP library.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2009, Günther Mair <guenther.mair@hoslo.ch>
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
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id$
 */

// create server instance
$server = new soap_server();

// initialize WSDL server as document/literal
$wsdl_ns = "eppitnic_php";
$wsdl_documentation = "Please see the 'WSDL interface specification' document for more details";

//
// produce 'rpc/literal' output:
//
// DON'T CHANGE TO 'document'!!!!! when using document/literal function
// parameters won't match!
//
// DONT' USE 'encoded' (deprecated)
//
$wsdl_style = 'rpc'; 
$wsdl_use = 'literal'; 
$server->configureWSDL($wsdl_ns, 'urn:'.$wsdl_ns, false, $wsdl_style);

//
// When registering a service the endpoint-value could be used to configure
// the SOAPACTION parameter:
//
//   $server->wsdl->endpoint.'#UpdateDomain'
//
// currently let's stick to the Namespace variant:
//
//   'urn:'.$wsdl_ns.'#DeleteDomain'
//

//$server->debug_flag = false;
//$server->wsdl->schemaTargetNamespace = $wsdl_ns;

/*
 * exit codes like 1000, 1001, 1002 et. al. are defined in 'statuscodes.php'
 * WSDL definitions for complex types (arrays and lists) are found in 'wsdl_typedefinitions.php'
 */
require_once 'Net/EPP/IT/WSDL/statuscodes.php';
require_once 'Net/EPP/IT/WSDL/wsdl_typedefinitions.php';

/*
 * included per method you will find:
 *  - a soap server registration for each SOAP method
 *  - a PHP function reflecting the SOAP method
 */

// ACCOUNT RELATED
require_once 'Net/EPP/IT/WSDL/account.credit.php';
require_once 'Net/EPP/IT/WSDL/account.poll-count.php';
require_once 'Net/EPP/IT/WSDL/account.poll.php';
require_once 'Net/EPP/IT/WSDL/account.poll-all.php';

// CONTACT RELATED METHODS
require_once 'Net/EPP/IT/WSDL/contact.create.php';
require_once 'Net/EPP/IT/WSDL/contact.check.php';
require_once 'Net/EPP/IT/WSDL/contact.info.php';
require_once 'Net/EPP/IT/WSDL/contact.update.php';
require_once 'Net/EPP/IT/WSDL/contact.delete.php';

// DOMAIN RELATED METHODS
require_once 'Net/EPP/IT/WSDL/domain.changeRegistrant.php';
require_once 'Net/EPP/IT/WSDL/domain.create.php';
require_once 'Net/EPP/IT/WSDL/domain.check.php';
require_once 'Net/EPP/IT/WSDL/domain.info.php';
require_once 'Net/EPP/IT/WSDL/domain.update.php';
require_once 'Net/EPP/IT/WSDL/domain.delete.php';
require_once 'Net/EPP/IT/WSDL/domain.restore.php';
require_once 'Net/EPP/IT/WSDL/domain.transfer-request.php';
require_once 'Net/EPP/IT/WSDL/domain.transfer-cancel.php';
require_once 'Net/EPP/IT/WSDL/domain.transfer-reject.php';
require_once 'Net/EPP/IT/WSDL/domain.transfer-approve.php';

/*
 * print output
 */
$server->service($HTTP_RAW_POST_DATA);  

