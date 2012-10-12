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
                        # find preauth in tblhosting and empty out the subscriptionid field
                        update_query('tblhosting',array('subscriptionid' => ''),array('subscriptionid'    => $aPreauth->id));
                        # log each preauth that has been cancelled
                        logTransaction($gateway['paymentmethod'],'GoCardless Preauthorisation Cancelled ('.$aPreauth->id.')','Cancelled');
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
                        $query = "SELECT gc.invoiceid AS invoiceid, gc.resource_id AS resource_id, i.total AS total, i.userid AS userid FROM tblinvoices AS i, mod_gocardless AS gc WHERE gc.invoiceid = i.id AND gc.resource_id = '".db_escape_string($aBill['id'])."'";
                        $result = full_query($query);
                        
                        # if one or more rows were returned
                        # (this happens when the bill has already been created in the database)
                        while($res = mysql_fetch_assoc($result)) {
                            # store invoice ID and total in params
                            list($invoiceid,$total) = array($res['invoiceid'],$res['total']);
                            
                            # get the bill we are referencing form GoCardless
                            $oBill = GoCardless_Bill::find($res['resource_id']);
                            
                            # convert currency where necessary (GoCardless only handles GBP)
                            if(($currency = getCurrency($res['userid']) != 'GBP') && ($gateway['currency'] == 'GBP')) {
                                # the users currency is not in GBP, convert to the users currency
                                $total = convertCurrency($oBill->amount,'GBP',$currency);
                                $fee = convertCurrency($oBill->fee,'GBP',$oBill->gocardless_fee);
                            } else {
                                # the users currency is in GBP, just set the $fee param
                                $fee = $oBill->gocardless_fee;
                            }
                            
                            # verify the invoice ID (to ensure it exists) and transaction ID to ensure it is unique
                            checkCbInvoiceID($invoiceid, $gateway['paymentmethod']);
                            checkCbTransID($oBill->id);

                            # if we get to this point, we have verified the callback and performed sanity checks
                            # add a payment to the invoice and create a transaction log
                            addInvoicePayment($invoiceid, $oBill->id, $total, $fee, $gateway['paymentmethod']);
                            logTransaction($gateway['paymentmethod'], 'Bill payment completed ('.$oBill->id.')', 'Successful');
                        }
                    }
                    break;
                    
                case 'failed':
                case 'refunded':
                    # loop through each bill that has failed or been refunded
                    foreach($val['bills'] as $aBill) {
                        
                        # attempt to obtain the mod_gocardless record
                        $aGC = mysql_fetch_assoc(select_query('mod_gocardless','invoiceid',array('resource_id'   => $aBill['id'])));
                        
                        # check we have a result, we will only process if we do
                        if(count($aGC)) {
                            
                            # load the corresponding invoice in $aInvoice array
                            $aInvoice = mysql_fetch_assoc(select_query('tblinvoices','status',array('id' => $aGC['invoiceid'])));
                            
                            # mark the GC record as failed (this will be displayed on the admin invoice page)
                            update_query('mod_gocardless',array('payment_failed' => 1),array('resource_id' => $aBill['id'], 'payment_failed' => 0));
                            
                            # check if the invoice is marked as paid already
                            if($aInvoice['status'] == 'Paid') {
                                # the invoice is marked as paid already (mark as paid instantly)
                                # update the corresponding transaction to mark as FAIL and mark the invoice as unpaid
                                update_query('tblaccounts', array('amountin' => "0", 'fees' => "0", 'transid' => ($val['action'] == 'failed' ? 'FAIL_' : 'REFUND_' . $aBill['id']),array('invoiceid' => $res['invoiceid'], 'transid' => $aBill['id'])));
                                update_query('tblinvoices', array('status' => 'Unpaid'), array('id' => $res['invoiceid']));
                            }
                            
                            # log the failed/refunded transaction in the gateway log as status 'Payment Failed/Refunded'
                            logTransaction($gateway['paymentmethod'],"GoCardless Payment {$val['action']}.\r\nPreauth ID: {$aBill['source_id']}\nBill ID: {$aBill['id']}: " . print_r($aBill,true),'Bill ' . ucfirst($val['action']));
                        }
                    }
                    break;
                case 'created':
                    # we dont want to handle created bills
                    foreach($val['bills'] as $aBill) {
                        logTransaction($gateway['paymentmethod'],'GoCardless Bill Created ('.$aBill['id'].')','Bill Created');
                    }
                    break;
            }
            break;
        default:
            header('HTTP/1.1 400 Bad Request');
            break;
    }
    
    # if we get to this point we are done
    header('HTTP/1.1 200 OK');