<?php

    /**
    * GoCardless WHMCS module
    *
    * @author WHMCS <info@whmcs.com>
    * @version 0.1.0
    */

    # load in all required files
    $whmcsdir = dirname(__FILE__) . '/../../../';
    require_once $whmcsdir . 'dbconnect.php';
    require_once $whmcsdir . '/includes/functions.php';
    require_once $whmcsdir . '/includes/gatewayfunctions.php';
    require_once $whmcsdir . '/includes/invoicefunctions.php';
    require_once $whmcsdir . '/modules/gateways/gocardless.php';


    # get gateway params using WHMCS getGatewayVariables method
    $gateway = getGatewayVariables('gocardless');

    # verify the gateway is installed
    if (!$gateway['type']) {
        die('Module not activated.');
    }

    # check if we are running testmode or not and set the API params accordingly
    if($gateway['test_mode']=='on'){
        GoCardless::set_account_details(array(
                'app_id'        => $gateway['dev_app_id'],
                'app_secret'    => $gateway['dev_app_secret'],
                'merchant_id'   => $gateway['dev_merchant_id'],
                'access_token'  => $gateway['dev_access_token'],
                'test_mode'     => $gateway['test_mode'],
                'ua_tag'        => 'gocardless-whmcs/v' . GC_WHMCS_VERSION
            ));
    } else {
        GoCardless::set_account_details(array(
                'app_id'        => $gateway['app_id'],
                'app_secret'    => $gateway['app_secret'],
                'merchant_id'   => $gateway['merchant_id'],
                'access_token'  => $gateway['access_token'],
                'test_mode'     => $gateway['test_mode'],
                'ua_tag'        => 'gocardless-whmcs/v' . GC_WHMCS_VERSION
            ));
    }

    # get the raw contents of the callback and decode JSON
    $webhook = file_get_contents('php://input');
    $webhook_array = json_decode($webhook, true);

    # validate the webhook by verifying the integrity of the payload
    if(GoCardless::validate_webhook($webhook_array['payload']) === true) {

        # store various elements of the webhook array into params
        $val = $webhook_array['payload'];
        $resource_type = $val['resource_type'];
        $action = $val['action'];
		
		# free up memory
		unset($webhook_array);

		# check the action we are performing
        if ($action == "paid") {
			
			# loop through the contents of bills
            foreach ($val['bills'] as $bill) {

                $transid 		= $bill['id'];

				# This query finds the invoiceid and invoice total from the database based on the bill ID
                $query = "SELECT gc.invoiceid AS invoiceid, i.total AS total FROM tblinvoices AS i, mod_gocardless AS gc WHERE gc.invoiceid = i.id AND gc.resource_id = '".db_escape_string($transid)."'";
                $result = full_query($query);
				
				# if one or more rows were returned
				if (mysql_num_rows($result)) {
					# get associative array and store in $res
                    $res = mysql_fetch_assoc($result);
					
					# store invoice ID and total in params
                    $invoiceid = $res['invoiceid'];
                    $total = $res['total'];
                    $fee = 0;
					
					# SANITY checks
					# verify the invoiceID, this will verify and if necessary kill and log the error
                    $invoiceid = checkCbInvoiceID($invoiceid, $gateway['name']);
					# halt script execution if a transaction by $transid has already been found
                    checkCbTransID($transid);

					# if we get to this point, we have verified the callback
					# made sure it is not a duplicate and checked the invoice ID we are paying to
					# now lets check if the status is paid or not
                    if ($bill['status'] == 'paid') {
						# OK the status is paid so we will add a payment to the invoice and create a transaction log
                        addInvoicePayment($invoiceid, $transid, $total, $fee, $gateway['name']);
                        logTransaction($gateway['name'], print_r($bill, 1), 'Successful');
                    } else {
						# status is not marked as paid, log the transaction with appropriate debug info
                        logTransaction($gateway['name'], print_r($bill, 1), 'Unsuccessful');
                    }

                } else {
					
					# we havent been able to find the invoiceid or total from the WHMCS database
					# we need an alternative method to find the appropriate invoice which we will do below

					# if the status is paid, we need to find the relevant invoice
                    if ($bill['status'] == 'paid') {
					
						# This query obtains the invoiceid and userid using a series of joins based upon the subscriptionid of the HOSTING service
                        $query = "SELECT tblinvoiceitems.invoiceid,tblinvoices.userid FROM tblhosting INNER JOIN tblinvoiceitems ON tblhosting.id = tblinvoiceitems.relid INNER JOIN tblinvoices ON tblinvoices.id = tblinvoiceitems.invoiceid WHERE tblinvoices.status = 'Unpaid' AND tblhosting.subscriptionid = '{$bill['resourceid']}' AND tblinvoiceitems.type = 'Hosting' ORDER BY tblinvoiceitems.invoiceid ASC";
                        $result = full_query($query);
						
						# get array of results and store in $res. Store this result in $invoiceid and $userid
						# we now have the invoiceid and userid to use
                        $res = mysql_fetch_array($result);
                        $invoiceid = $res['invoiceid'];
                        $userid = $res['userid'];
						
						# attempt to find the bill from GoCardless
						$bill = GoCardless_Bill::find($transid);

						# check we have $invoiceid
                        if ($invoiceid) {
							
							# get the GoCardless bill amount
                            $mc_gross = $bill->amount;

							# get the users currency
                            $currency = getCurrency($userid);

							# query the current rate for the currency in question
							# base the conversion on GBP conversion rates
                            $result = select_query('tblcurrencies', '', array('code' => 'GBP'));
                            $data = mysql_fetch_assoc($result);

                            $currencyid = $data['id'];
                            $currencyconvrate = $data['rate'];

							# if the user has a different currency to GBP (the GoCardless default) then
							# convert it based on WHMCS rates
                            if ($currencyid != $currency['id']) {
                                $mc_gross = convertCurrency($mc_gross, $currencyid, $currency['id']);
                                $mc_fee = 0;
                            }
							
							# attempt to add invoice payment and log transaction if successful
                            if(addInvoicePayment($invoiceid, $transid, $mc_gross, $mc_fee, 'gocardless')) {
								# set transaction log message
								$m = "Invoice Found from Subscription ID Match => $invoiceid\n";
								logTransaction('GoCardless', $m , 'Successful');
								
								# add the subscription ID to the 
								$result = select_query('tblinvoiceitems', '', array('invoiceid' => $invoiceid, 'type' => 'Hosting'));
								$data = mysql_fetch_array($result);
								$relid = $data['relid'];
								update_query('tblhosting', array('subscriptionid' => $resourceid), array('id' => $relid));

								exit;
							}

                        }

                    } else {
						# the payment was not marked as paid so at this time we will just
						# log the attempt in the WHMCS transaction log
                        logTransaction($gateway['name'], $m.print_r($bill, 1), 'Incomplete');
                        exit;

                    }

                }

            }
            // end foreach

        } elseif ($action=="cancelled") {


            foreach ($val['pre_authorizations'] as $key => $val) {

                $id = $val['id'];
                //check resource id exists in tblhosting
                //If it exists update tblhosting so that no futher invoices are generated againt this id
                //Log results

                $result = select_query('tblhosting', 'id', array('subscriptionid' => $id));      
                $data = mysql_fetch_assoc($result);
                if ($data['id']){
                    update_query("tblhosting",array("subscriptionid"=>''),array("subscriptionid"=>$id));
                    logTransaction($gateway['name'], print_r($val, 1), 'Successful');
                } else {
                    logTransaction($gateway['name'], print_r($val, 1), 'Unsuccessful');          
                }

            }

        }

        header('HTTP/1.1 200 OK');

    } else {

        header('HTTP/1.1 403 Invalid signature'.$gateway['app_secret']);

    }
