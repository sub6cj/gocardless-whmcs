<?php

    /**
    * GoCardless WHMCS module redirect.php
    * This file confirms verifies a preauth and creates a bill underneath it
    *
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
    error_reporting(E_ALL ^ E_NOTICE);

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
            logTransaction($gateway['name'],'GoCardless Redirect Failed (Resource not verified) : ' .print_r($_GET,true) . 'Exception: ' . print_r($e,true),'Unsuccessful');
            header('HTTP/1.1 400 Bad Request');
            exit('Your request could not be completed');
        }
        
    } else {
        # failed to get resource ID and resource type, invalid request. Log transaction and ouput error message to client
        logTransaction($gateway['name'],'GoCardless Redirect Failed (No data provided) : ' .print_r($_GET,true),'Unsuccessful');
        header('HTTP/1.1 400 Bad Request');
        exit('Your request could not be completed');
    }

    # split invoice data into invoiceID and invoiceAmount
    list($invoiceID,$invoiceAmount) = explode(':', $_GET['state']);

    # check we have the invoiceID
    if($invoiceID) {
        
        # check this invoice exists (halt execution if it doesnt)
        checkCbInvoiceID($invoiceID, $gateway['name']);

        # select client record from database
        $d = select_query('tblclients', 'gatewayid', array('id' => $res['userid']));
        $res = mysql_fetch_assoc($d);

        # if their gatewayid is blank, set it to gocardless
        if (empty($res2['gatewayid'])) {
            update_query('tblclients', array('gatewayid' => 'gocardless'), array('id' => $res['userid']));
        }

        # check if we are handling a preauth or a one time bill
        switch ($_GET['resource_type']) {

            case "pre_authorization":
                # the resource is a preauth, we need to create the users first bill
                # verify the preauth
                $pre_auth = GoCardless_PreAuthorization::find($_GET['resource_id']);
                
                $preauth_id = $_GET['resource_id'];
                
                # create a GoCardless bill and store it in $bill
                $bill = $pre_auth->create_bill(array('amount' => $invoiceAmount));

                # if we have been able to create the bill, the preauth ID being null suggests payment is pending
                if ($bill->id) {
                    $billID = $bill->id;
                    insert_query('mod_gocardless', array('invoiceid' => $invoiceID, 'billcreated' => 1, 'resource_id' => $bill->id, 'preauth_id' => $preauth_id));
                }
                
                # query tblinvoiceitems to get the related service ID
                $d = select_query('tblinvoiceitems', 'relid', array('type' => 'Hosting', 'invoiceid' => $invoiceID));

                # update subscription ID with the resource ID on all HOSTING type services corresponding with the invoice
                while ($res = mysql_fetch_assoc($d)) {
                    update_query('tblhosting', array('subscriptionid' => $preauth_id), array('id' => $res['relid']));
                }
                
                # clean up
                unset($preauth_id,$pre_auth,$d,$res);
                break;

            case 'bill':
                # the response is a one time bill, we need to add the bill to the database
                $billID = $_GET['resource_id'];
                insert_query('mod_gocardless', array('invoiceid' => $invoiceID, 'billcreated' => 1, 'resource_id' => $_GET['resource_id']));
                break;
                
            default:
                # we cannot handle anything other than a bill or preauths
                header('HTTP/1.1 400 Bad Request');
                exit('Your request could not be completed');
                break;
        }
        
        # check if we should be marking the bill as paid instantly
        if($gateway['instantpaid'] == 'on') {
            # process the payment to instantly mark it as paid
            $bill = GoCardless_Bill::find($billID);
            $mc_gross = $bill->amount;
            
            # mark the invoice as paid
            addInvoicePayment($invoiceID, $bill->id, $bill->amount, $bill->gocardless_fees, $gateway['name']);
            logTransaction($gateway['name'], 'GoCardless Bill ('.$_GET['resource_type'].')Instant Paid: ' . print_r($bill, true), 'Successful');
        } else {
            # log payment pending
            $bill = GoCardless_Bill::find($billID);
            logTransaction($gateway['name'],'GoCardless Bill ('.$_GET['resource_type'].')Pending','Pending');
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
    
    exit('EOF');