<?php

    /**
    * GoCardless WHMCS module
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


    # get gateway params using WHMCS getGatewayVariables method
    $gateway = getGatewayVariables('gocardless');

    # sanity check to ensure module is active
    if (!$gateway['type']) die("Module Not Activated");

    # set relevant API information for GoCardless module
    gocardless_set_account_details($gateway);

    # get the raw contents of the callback and decode JSON
    $webhook = file_get_contents('php://input');
    $webhook_array = json_decode($webhook, true);
    
    # validate the webhook by verifying the integrity of the payload with GoCardless
    if(GoCardless::validate_webhook($webhook_array['payload']) !== true) {
        # we could not validate the web hook
        header('HTTP/1.1 400 Bad Request');
        exit;
    }

    # store various elements of the webhook array into params
    $val = $webhook_array['payload'];
    
    # base what we are doing depending on the resource type
    switch($val['resource_type']) {
        case 'pre_authorization':
            
            # handle preauths (possible actions - cancelled, expired)
            switch($val['action']) {
                
                # handle cancelled or expired preauths
                case 'cancelled':
                case 'expired':
                    # delete related preauths
                    foreach ($val['pre_authorizations'] as $aPreauth) {
                        # find preauth in tblhosting & tbldomains and empty out the subscriptionid field
                        update_query('tblhosting',array('subscriptionid' => NULL),array('subscriptionid'    => $aPreauth->id));
                        update_query('tbldomains',array('subscriptionid' => NULL),array('subscriptionid'    => $aPreauth->id));
                        # log each preauth that has been cancelled
                        logTransaction($gateway['paymentmethod'],'GoCardless Preauthorisation Cancelled: ' . print_r($aPreauth,true),'Cancelled');
                    }
                    break;
                default:
                    # we cannot handle this request
                    header('HTTP/1.1 400 Bad Request');exit;
                    break;
            }
            
            break;
        case 'bill':
        
            # handle bills (possible actions - created, failed, paid, withdrawn, refunded)
            switch($val['action']) {
                case 'paid':
                    # mark the appropriate bill as paid
                    # This query finds the invoiceid and invoice total from the database based on the bill ID
                    foreach($val['bills'] as $aBill) {
                        $query = "SELECT gc.invoiceid AS invoiceid, gc.resource_id, i.total AS total FROM tblinvoices AS i, mod_gocardless AS gc WHERE gc.invoiceid = i.id AND gc.resource_id = '".db_escape_string($aBill['id'])."'";
                        $result = full_query($query);
                        
                        # if one or more rows were returned
                        # (this happens when the bill has already been created in the database)
                        while($res = mysql_fetch_assoc($result)) {
                            # store invoice ID and total in params
                            list($invoiceid,$total) = array($res['invoiceid'],$res['total']);
                            
                            # get the bill we are referencing form GoCardless
                            $oBill = GoCardless_Bill::find($res['resource_id']);
                            
                            # check the bills values match up, if they dont we will log it and exit
                            if($oBill->amount <> $total) {
                                logTransaction($gateway['paymentmethod'],'Unexpected bill value. Expecting ' . $total . "\n" . print_r($oBill,true),'Failed');
                                header('HTTP/1.1 400 Bad Request'); exit;
                            }
                            
                            # verify the invoiceID to ensure it exists and check a transaction by this ID hasnt already been recorded
                            checkCbInvoiceID($invoiceid, $gateway['paymentmethod']);
                            checkCbTransID($oBill->id);

                            # if we get to this point, we have verified the callback and performed sanity checks
                            # add a payment to the invoice and create a transaction log
                            addInvoicePayment($invoiceid, $oBill->id, $total, $oBill->gocardless_fees, $gateway['paymentmethod']);
                            logTransaction($gateway['paymentmethod'], print_r($oBill, true), 'Successful');
                        }
                    }
                    break;
                case 'failed':
                case 'refunded':
                    # log each bill as being failed
                    foreach($val['bills'] as $aBill) {
                        # check if the bill corresponds to an invoice
                        $d = select_query('mod_gocardless','invoiceid',array('resource_id'   => $aBill['id']));
                        
                        if($val['action'] == 'failed') {
                            # update mod_gocardless to suggest a failed payment
                            update_query('mod_gocardless',array('payment_failed' => 1),array('resource_id' => $aBill['id'], 'payment_failed' => 0));
                        }
                        while($res = mysql_fetch_assoc($d)) { # $res stored invoiceid
                            
                            # load the corresponding invoice
                            $d2 = select_query('tblinvoices','status',array('id' => $res['invoiceid']));
                            
                            # check if the invoice is paid
                            while($res2 = mysql_fetch_assoc($d2)) { #res2 stores status
                                if($res2['status'] == 'Paid') {
                                    # the invoice is marked as paid already (mark as paid instantly)
                                    # update the corresponding transaction to mark as FAIL and mark the invoice as unpaid
                                    update_query('tblaccounts', array('amountin' => "0", 'fees' => "0", 'transid' => ($val['action'] == 'failed' ? 'FAIL_' : 'REFUND_' . $aBill['id']),array('invoiceid' => $res['invoiceid'], 'transid' => $aBill['id']));
                                    update_query('tblinvoices', array('status' => 'Unpaid'), array('id' => $res['invoiceid']));
                                }
                            }
                        }
                        # log the failed transaction in the gateway log
                        logTransaction($gateway['paymentmethod'],"GoCardless Payment Failed.\r\nPreauth ID: {$aBill['source_id']}\nBill ID: {$aBill['id']}: " . print_r($aBill,true),'Failed');
                    }
                    break;
                case 'created':
                    # we dont want to handle created bills
                    break;
            }
            break;
        default:
            header('HTTP/1.1 400 Bad Request');
            break;
    }
    
    # if we get to this point we are done
    header('HTTP/1.1 200 OK');