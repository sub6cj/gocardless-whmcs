<?php

    /**
    * GoCardless WHMCS module redirect.php
    * This file confirms verifies a preauth and creates a bill underneath it
    * Either a one of payment (bill) or a pre authorisation can be handled by this file
    * @author WHMCS <info@whmcs.com>
    * @version 0.1.0
    */

    # load all required files
    $whmcsdir = dirname(__FILE__) . '/../../../';
    require_once $whmcsdir . 'dbconnect.php';
    require_once $whmcsdir . '/includes/functions.php';
    require_once $whmcsdir . '/includes/gatewayfunctions.php';
    require_once $whmcsdir . '/includes/invoicefunctions.php';
    require_once $whmcsdir . '/modules/gateways/gocardless.php';

    # get gateway params
    $gateway = getGatewayVariables('gocardless');

    # sanity check to ensure module is active
    if (!$gateway['type']) die("Module Not Activated");

    # set relevant API information for GoCardless module
    gocardless_set_account_details($gateway);


    # if the resource ID and resouce type are set, confirm it using the GoCardless API
    if (isset($_GET['resource_id']) && isset($_GET['resource_type'])) {

        # if GoCardless fails to confirm the resource, an exception will be thrown
        # we will handle the exception gracefully
        try {
            $confirmed_resource = GoCardless::confirm_resource(array(
                    'resource_id'   => $_GET['resource_id'],
                    'resource_type' => $_GET['resource_type'],
                    'resource_uri'  => $_GET['resource_uri'],
                    'signature'     => $_GET['signature'],
                    'state'         => $_GET['state']
                ));
        } catch(Exception $e) {
            # failed to verify the resource with GoCardless. Log transaction and ouput error message to client
            logTransaction($gateway['paymentmethod'],'GoCardless Redirect Failed (Resource not verified) : ' .print_r($_GET,true) . 'Exception: ' . print_r($e,true),'Unsuccessful');
            header('HTTP/1.1 400 Bad Request');
            exit('Your request could not be completed');
        }

    } else {
        # failed to get resource ID and resource type, invalid request. Log transaction and ouput error message to client
        logTransaction($gateway['paymentmethod'],'GoCardless Redirect Failed (No data provided) : ' .print_r($_GET,true),'Unsuccessful');
        header('HTTP/1.1 400 Bad Request');
        exit('Your request could not be completed');
    }

    # split invoice data into invoiceID and invoiceAmount
    list($invoiceID,$invoiceAmount) = explode(':', $_GET['state']);

    # check we have the invoiceID
    if($invoiceID) {

        # check this invoice exists (halt execution if it doesnt)
        checkCbInvoiceID($invoiceID, $gateway['paymentmethod']);

        # get user ID and gateway ID for use further down the script
        $userID = mysql_result(select_query('tblinvoices','userid',array('id' => $invoiceID)),0,0);
        $gatewayID = mysql_result(select_query('tblclients', 'gatewayid', array('id' => $userID)),0,0);

        # if the user records gateway is blank, set it to gocardless
        if (empty($gatewayID)) {
            update_query('tblclients', array('gatewayid' => $gateway['paymentmethod']), array('id' => $userID));
        }

        # check if we are handling a preauth or a one time bill
        switch ($_GET['resource_type']) {

            case "pre_authorization":

            # get the confirmed resource (pre_auth) and created a referenced param $pre_auth
            $pre_auth = &$confirmed_resource;

            # check if we have a setup_fee
            $setup_id = null;
            if($pre_auth->setup_fee > 0) {
                # store the bill in $oSetupBill for later user
                $aoSetupBills = $pre_auth->bills();
                $oSetupBill = $aoSetupBills[0];
                $setup_id = $oSetupBill->id;
                unset($aoSetupBills);
            }

            # create a GoCardless bill and store it in $bill
            try {
                $oBill = $pre_auth->create_bill(array('amount' => $invoiceAmount));
            } catch (Exception $e) {
                # log that we havent been able to create the bill and exit out
                logTransaction($gateway['paymentmethod'],'Failed to create new bill: ' . print_r($e,true),'GoCardless Error');
                exit('Your request could not be completed');
            }

            # if we have been able to create the bill, the preauth ID being null suggests payment is pending
            # (this will display in the admin)
            if ($oBill->id) {
                insert_query('mod_gocardless', array('invoiceid' => $invoiceID, 'billcreated' => 1, 'resource_id' => $oBill->id, 'setup_id' => $setup_id, 'preauth_id' => $pre_auth->id));
            }

            # query tblinvoiceitems to get the related service ID
            # update subscription ID with the resource ID on all hosting services corresponding with the invoice
            $d = select_query('tblinvoiceitems', 'relid', array('type' => 'Hosting', 'invoiceid' => $invoiceID));
            while ($res = mysql_fetch_assoc($d)) {
                if($res['type'] == 'Hosting') {
                    update_query('tblhosting', array('subscriptionid' => $pre_auth->id), array('id' => $res['relid']));
                }
            }

            # clean up
            unset($d,$res);
            break;

            case 'bill':
                # the response is a one time bill, we need to add the bill to the database
                $oBill = $confirmed_resource;
                insert_query('mod_gocardless', array('invoiceid' => $invoiceID, 'billcreated' => 1, 'resource_id' => $oBill->id));
                break;

            default:
                # we cannot handle anything other than a bill or preauths
                header('HTTP/1.1 400 Bad Request');
                exit('Your request could not be completed');
                break;
        }

        # check if we should be marking the bill as paid instantly
        if($gateway['instantpaid'] == 'on') {

            # convert currency where necessary (GoCardless only handles GBP)
            $aCurrency = getCurrency($res['userid']);
            if($gateway['convertto'] && ($aCurrency['id'] != $gateway['convertto'])) {
                # the users currency is not the same as the GoCardless currency, convert to the users currency
                $oBill->amount = convertCurrency($oBill->amount,$gateway['convertto'],$aCurrency['id']);
                $oBill->gocardless_fee = convertCurrency($oBill->gocardless_fee,$gateway['convertto'],$aCurrency['id']);

                # currency conversion on the setup fee bill
                if(isset($oSetupBill)) {
                    $oSetupBill->amount = convertCurrency($oBill->amount,$gateway['convertto'],$aCurrency['id']);
                    $oSetupBill->gocardless_fee = convertCurrency($oBill->gocardless_fee,$gateway['convertto'],$aCurrency['id']);
                }
            }

            # check if we are handling a preauth setup fee
            # if we are then we need to add it to the total bill
            if(isset($oSetupBill)) {
                addInvoicePayment($invoiceID, $oSetupBill->id, $oSetupBill->amount, $oSetupBill->gocardless_fees, $gateway['paymentmethod']);
            }

            # add the payment to the invoice
            addInvoicePayment($invoiceID, $oBill->id, $oBill->amount, $oBill->gocardless_fees, $gateway['paymentmethod']);
            logTransaction($gateway['paymentmethod'], 'GoCardless Bill ('.$oBill->id.')Instant Paid: ' . print_r($oBill, true), 'Successful');
        } else {

            # log payment as pending
            logTransaction($gateway['paymentmethod'],'GoCardless Bill ('.$oBill->id.') Pending','Pending');
        }

        # if we get to this point, we have verified everything we need to, redirect to invoice
        $systemURL = ($CONFIG['SystemSSLURL'] ? $CONFIG['SystemSSLURL'] : $CONFIG['SystemURL']);
        header('HTTP/1.1 303 See Other');
        header("Location: {$systemURL}/viewinvoice.php?id={$invoiceID}");
        exit();

    } else {
        # we could not get an invoiceID so cannot process this further
        header('HTTP/1.1 400 Bad Request');
        exit('Your request could not be completed');
}