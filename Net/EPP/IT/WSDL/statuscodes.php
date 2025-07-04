<?php

// ===================================================================================
// ======== generic error codes ======================================================
// ===================================================================================

// success
$soapState['generic'][1000] = 'The operation was successful.';

// failures
$soapState['generic'][2000] = 'The operation failed. Please see the statusDescription field for more details.';
$soapState['generic'][2001] = 'User authentication failed.';
$soapState['generic'][2002] = 'Connection to server failed.';
$soapState['generic'][2003] = 'You have not specified all mandatory fields.';
$soapState['generic'][2004] = 'The smarty/compile folder is not writeable by the current user (webserver?).';

// ===================================================================================
// ======== method specific error codes ==============================================
// ===================================================================================

// success
$soapState['PollReq'][3001] = 'The operation was successful, but there was no message in the queue.';

// failures
$soapState['PollAll'][4001] = 'The operation to retrieve all message from the queue failed once or more.';

$soapState['ContactCreate'][4001] = 'Creation of contact failed.';
$soapState['ContactCreate'][4002] = 'Creation of contact failed, the specified handle/ID is already in use.';
$soapState['ContactDelete'][4001] = 'Failed to delete contact.';
$soapState['ContactInfo'][4001] = 'Failed to fetch contact information from server.';
$soapState['ContactUpdate'][4001] = 'Update of contact failed.';
$soapState['ContactUpdate'][4002] = 'Update of contact failed, the specified handle/ID is free.';
$soapState['ContactUpdate'][4003] = 'Update of contact failed, unable to fetch the specified handle/ID.';

$soapState['DomainCreate'][4001] = 'Creation of the domain failed.';
$soapState['DomainCreate'][4002] = 'Creation of the domain failed, the specified domain is already in use.';
$soapState['DomainDelete'][4001] = 'Failed to delete domain.';
$soapState['DomainRestore'][4001] = 'Failed to restore domain.';
$soapState['DomainInfo'][4001] = 'Failed to fetch domain information from server.';
$soapState['DomainUpdate'][4001] = 'Update of domain failed.';
$soapState['DomainUpdate'][4002] = 'Update of domain failed, the specified domain is free.';
$soapState['DomainUpdate'][4003] = 'Update of domain failed, unable to fetch the specified domain.';

$soapState['DomainTransferRequest'][4001] = 'Failed to request domain transfer.';
$soapState['DomainTransferCancel'][4001] = 'Failed to cancel domain transfer request.';
$soapState['DomainTransferApprove'][4001] = 'Failed to approve domain transfer request.';
$soapState['DomainTransferReject'][4001] = 'Failed to reject domain transfer request.';
