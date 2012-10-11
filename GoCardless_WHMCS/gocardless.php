<?php
    /**
    * GoCardless WHMCS module
    *
    * @author WHMCS <info@whmcs.com>
    * @version 0.1.0
    */

    # load GoCardless library
    require_once ROOTDIR . '/modules/gateways/gocardless/GoCardless.php';

    define('GC_VERSION', '0.1.0');
    
    function po($val,$kill=true) {
        echo '<pre>'.print_r($val,true);$kill ? exit : null;
    }

    /**
    ** GoCardless configuration for WHMCS
    ** This method is used by WHMCS to establish the configuration information
    ** used within the admin interface. These params are then stored in `tblpaymentgateways`
    **/
    function gocardless_config() {

        $aConfig = array(
            'FriendlyName'      => array('Type' => 'System', 'Value' => 'GoCardless'),
            'merchant_id'       => array('FriendlyName' => 'Merchant ID', 'Type' => 'text', 'Size' => '15', 'Description' => '<a href="http://gocardless.com/merchants/new">Sign up</a> for a GoCardless account then find your API keys in the Developer tab'),
            'app_id'            => array('FriendlyName' => 'App ID', 'Type' => 'text', 'Size' => '100'),
            'app_secret'        => array('FriendlyName' => 'App Secret', 'Type' => 'text', 'Size' => '100'),
            'access_token'      => array('FriendlyName' => 'Access Token', 'Type' => 'text', 'Size' => '100'),
            'dev_merchant_id'   => array('FriendlyName' => 'Sandbox Merchant ID', 'Type' => 'text', 'Size' => '15', 'Description' => 'Use your GoCardless login details to access the <a href="http://sandbox.gocardless.com/">Sandbox</a> and then find your API keys in the Developer tab'),
            'dev_app_id'        => array('FriendlyName' => 'Sandbox App ID', 'Type' => 'text', 'Size' => '100'),
            'dev_app_secret'    => array('FriendlyName' => 'Sandbox App Secret', 'Type' => 'text', 'Size' => '100'),
            'dev_access_token'  => array('FriendlyName' => 'Sandbox Access Token', 'Type' => 'text', 'Size' => '100'),
            'oneoffonly'        => array('FriendlyName' => 'One Off Only', 'Type' => 'yesno', 'Description' => 'Tick to only perform one off captures - no recurring pre-auth agreements'),
            'instantpaid'       => array('FriendlyName' => 'Instant Activation', 'Type' => 'yesno', 'Description' => 'Tick to immediately mark invoices paid after payment is initiated (despite clearing not being confirmed for 3-5 days)', ),
            'test_mode'         => array('FriendlyName' => 'Test Mode', 'Type' => 'yesno', 'Description' => 'Tick to enable test mode', ),
        );

        return $aConfig;

    }
    
    /**
    * Checks whether test mode is enabled or disabled
    * and sets appropriate details against GoCardless object
    * @param array $params Array of parameters that contains gateway details
    */
    function gocardless_set_account_details($params=null) {
        
        # check if params have been supplied, if not attempt
        # to use global params
        if(is_null($params)) {
            unset($params);
            global $params;
        }
        global $CONFIG;
        
        # check we have been able to obtain the correct params
        if(!isset($params['app_id'])) {
            throw new Exception('Could not get GoCardless params');
        }
        
        # check if we are running in Sandbox mode (test_mode)
        if($params['test_mode'] == 'on') {
            # Initialise SANDBOX Account Details
            GoCardless::$environment = 'sandbox';
            GoCardless::set_account_details(array(
                'app_id'        => $params['dev_app_id'],
                'app_secret'    => $params['dev_app_secret'],
                'merchant_id'   => $params['dev_merchant_id'],
                'access_token'  => $params['dev_access_token'],
                'redirect_uri'  => $CONFIG['SystemURL'].'/modules/gateways/gocardless/redirect.php',
                'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
            ));
        } else {
            # Initialise LIVE Account Details
            GoCardless::set_account_details(array(
                'app_id'        => $params['app_id'],
                'app_secret'    => $params['app_secret'],
                'merchant_id'   => $params['merchant_id'],
                'access_token'  => $params['access_token'],
                'redirect_uri'  => $CONFIG['SystemURL'].'/modules/gateways/gocardless/redirect.php',
                'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
            ));
        }
    }
    
    /**
    ** Builds the payment link for WHMCS users to be redirected to GoCardless
    **/
    function gocardless_link($params) {

        # get global config params
        global $CONFIG;

        # create GoCardless database if it hasn't already been created
        gocardless_createdb();
        
        # check the invoice, to see if it has a record with a valid resource ID. If it does, the invoice is pending payment
        $pendingid = get_query_val('mod_gocardless', 'id', array('invoiceid' => $params['invoiceid'], 'resource_id' => array('sqltype' => 'NEQ', 'value' => '')));
		
		# check if a result was returned from the mod_gocardless table (if it has there is a pending payment)
        if ($pendingid) {
            # Pending Payment Found - Prevent Duplicate Payment with a Msg
            return '<strong>Your payment is currently pending and will be processed within 3-5 days.</strong>';
        } else {
            # we need to create a payment form to submit to GoCardless, to do this we should work out the maximum possible amount to charge
			# get tax rates
            $data = get_query_vals("tblinvoices", "taxrate,taxrate2", array('id' => $params['invoiceid']));
            list($taxrate,$taxrate2) = array($data["taxrate"],$data['taxrate2']);
            unset($data);
			
			# if $taxrate is 0 and the tax is all inclusive, then set appropriate tax rate
			if(!$taxrate && $CONFIG['TaxType'] == 'Inclusive') {
                # tax is inclusive
				$taxrate = 1;
			} else {
                # 1.x of the original value
				$taxrate = ($taxrate /100) + 1;
			}

			# set params $maxamount & $recurfrequency to 0
            $maxamount = $setupfee = $recurfrequency = 0;

            # check if the plugin configuration is set to make one of payments only
            if (!$params['oneoffonly']) {
                
                $d = select_query('tblinvoiceitems','relid,type,amount,taxed',array('invoiceid' => $params['invoiceid']));
                
                # loop through each invoice item on the table
                while($data = mysql_fetch_assoc($d)) {
                    
                    # check if the item is taxable
                    $itemtaxed = ($data['taxed'] ? $taxrate : 1);
                    
                    $aItemRecurVals = array();
                    switch($data['type']) {
                        
                        case 'Hosting':
                        
                            # Handle hosting service, pull relevant info from database
                            $aItemRecurVals = get_query_vals("tblhosting", "firstpaymentamount,amount,billingcycle", array('id' => $data['relid']));
                            # if the firstpaymentamount is greater than the amount, we need to include a setup fee
                            if($aItemRecurVals['firstpaymentamount'] > $aItemRecurVals['amount']) {
                                $setupfee+= $aItemRecurVals['firstpaymentamount'];
                            }
                            # add to monthly max amount
                            $maxamount+= $aItemRecurVals['amount'];
                            # check if we have the lowest possible recur frequency
                            if(($recurfrequency > getBillingCycleMonths($aItemRecurVals['billingcycle'])) or ($recurfrequency == 0)) {
                                $recurfrequency = getBillingCycleMonths($aItemRecurVals['billingcycle']);
                            }
                            
                            break;
                        case 'Addon':
                        
                            # Handle product addon
                            $aItemRecurVals = get_query_vals("tblhostingaddons", "setupfee,recurring,billingcycle", array('id' => $data['relid']));
                            # append the setup fee and max amount to the existing values
                            $setupfee+= $aItemRecurVals['setupfee'];
                            $maxamount+= $aItemRecurVals['recurring'];
                            # check if we have the lowest possible recur frequency
                            if($recurfrequency > getBillingCycleMonths($aItemRecurVals['billingcycle'])  or ($recurfrequency == 0)) {
                                $recurfrequency = getBillingCycleMonths($aItemRecurVals['billingcycle']);
                            }
                            
                            break;
                        case 'DomainRegister':
                        case 'DomainRenew':
                        case 'DomainTransfer':
                        
                            # Handle domain names
                            $aItemRecurVals = get_query_vals("tbldomains", "firstpaymentamount,recurringamount", array('id' => $data['relid']));
                            
                            # if the firstpayment amount is greater than the recurring amount, we need to include a setup fee
                            if($aItemRecurVals['firstpaymentamount'] > $aItemRecurVals['recurringamount']) {
                                $setupfee+= $aItemRecurVals['firstpaymentamount'];
                            }
                            $maxamount+= $aItemRecurVals['recurringamount'];
                            
                            break;
                        default:
                            # Handle items that have no type (setup fee, no recurrance)
                            break;
                    }
                    
                }
                unset($res,$d);

            }
            
            # set appropriate GoCardless API details
            gocardless_set_account_details($params);

			# set user array based on params parsed to $link
            $aUser = array(
                'first_name'        => $params['clientdetails']['firstname'],
                'last_name'         => $params['clientdetails']['lastname'],
                'email'             => $params['clientdetails']['email'],
                'billing_address1'  => $params['clientdetails']['address1'],
                'billing_address2'  => $params['clientdetails']['address2'],
                'billing_town'      => $params['clientdetails']['city'],
                'billing_county'    => $params['clientdetails']['state'],
                'billing_postcode'  => $params['clientdetails']['postcode'],
            );
            
            # currency conversion
			
			# if the valuation of $maxamount is false, we are making a one off payment
            if (!$maxamount) {
				# we are making a one off payment, display the appropriate code
				# Button title
                $title = 'Pay Now with GoCardless';
				
				# create GoCardless one off payment URL using the GoCardless library
                $url = GoCardless::new_bill_url(array(
					'amount'  => $params['amount'],
					'name'    => $params['description'],
					'user'    => $aUser,
					'state'   => $params['invoiceid'] . ':' . $params['amount']
				));

                # return one time payment button code
                return (GoCardless::$environment == 'sandbox' ? '<strong style="color: #FF0000; font-size: 16px;">SANDBOX MODE</strong><br />' : null) . '<a href="'.$url.'" style="text-decoration: none"><input type="button" value="'.$title.'" /></a>';

            } else {
                # we are setting up a recurring payment, display the appropriate code
				
				# Button title
                $title = 'Create Subscription with GoCardless';
				
				# create GoCardless preauth URL using the GoCardless library
                $url = GoCardless::new_pre_authorization_url(array(
					'max_amount'      => $maxamount,
                    'setup_fee'       => $setupfee,
					'name'            => $description,
					'interval_length' => $recurfrequency,
					'interval_unit'   => 'month',
					'user'            => $aUser,
					'state'           => $params['invoiceid'] . ':' . $params['amount']
				));

                # return the recurring preauth button code
                return (GoCardless::$environment == 'sandbox' ? '<strong style="color: #FF0000; font-size: 16px;">SANDBOX MODE</strong><br />' : null) . 'When you get to GoCardless you will see an agreement for the <b>maximum possible amount</b> we\'ll ever need to charge you in a single invoice for this order, with a frequency of the shortest item\'s billing cycle. But rest assured we will never charge you more than the actual amount due.
                <br /><a href="'.$url.'" style="text-decoration: none"><input type="button" value="'.$title.'" /></a>';

            }
        }
    }

	/**
	** WHMCS method to capture payments
	** This method is triggered by WHMCS in an attempt to capture a PreAuth payment
	**
	** @param array $params Array of paramaters parsed by WHMCS
	**/
    function gocardless_capture($params) {
		
		# create GoCardless DB if it hasn't already been created
        gocardless_createdb();
		
		# Send the relevant API information to the GoCardless class for future processing
        gocardless_set_account_details($params);

		# check against the database if the bill relevant to this invoice has already been created
        $existing_payment_query = select_query('mod_gocardless', 'resource_id', array('invoiceid' => $params['invoiceid']));
        $existing_payment = mysql_fetch_assoc($existing_payment_query);

		# check if any rows have been returned or if the returned result is empty.
		# If no rows were returned, the bill has not already been made for this invoice
		# If a row was returned but the resource ID is empty, the bill has not been completed
		# we have already raised a bill with GoCardless (in theory)
        if (!mysql_num_rows($existing_payment_query) || empty($existing_payment['resource_id'])) {
			
			# query the database to get the relid of all invoice items
            $invoice_item_query = select_query('tblinvoiceitems', 'relid', array('invoiceid' => $params['invoiceid'], 'type' => 'Hosting'));
			
			# loop through each returned (each invoice item) and attempt to find a subscription ID
            while ($invoice_item = mysql_fetch_assoc($invoice_item_query)) {
                $package_query = select_query('tblhosting', 'subscriptionid', array('id' => $invoice_item['relid']));
                $package = mysql_fetch_assoc($package_query);
				
				# if we have found a subscriptionID, store it in $preauthid
                if (!empty($package['subscriptionid'])) {
                    $preauthid = $package['subscriptionid'];
                }
            }
			
			# now we are out of the loop, check if we have been able to get the PreAuth ID
            if (isset($preauthid)) {
				
				# we have found the PreAuth ID, so get it from GoCardless and process a new bill
				
                $pre_auth = GoCardless_PreAuthorization::find($preauthid);
				
				# check the preauth returned something
				if($pre_auth) {
					
					# Create a bill with the $pre_auth object
					$bill = $pre_auth->create_bill(array('amount' => $params['amount']));
					
					# check that the bill has been created
					if ($bill->id) {
						# check if the bill already exists in the database, if it does we will just update the record
						# if not, we will create a new record and record the transaction
						if (!mysql_num_rows($existing_payment_query)) {
							# Add the bill ID to the table and mark the transaction as pending
							insert_query('mod_gocardless', array('invoiceid' => $params['invoiceid'], 'billcreated' => 1, 'resource_id' => $bill->id, 'preauth_id'  => $pre_auth->id));
							logTransaction('GoCardless', 'Transaction initiated successfully, confirmation will take 2-5 days' . "\nPreAuth: " . $pre_auth->id . "\nBill ID: " . $bill->id, 'Pending');
						} else {
							# update the table with the bill ID
							update_query('mod_gocardless', array('billcreated' => 1, 'resource_id' => $bill->id), array('invoiceid' => $params['invoiceid']));
						}

					}
				} else {
					# PreAuth could not be verified
					logTransaction('GoCardless','Pre-Authorisation could not be verified','Incomplete');
				}
				

            } else {
				# we couldn't find the PreAuthID meaning at this point all we can do is give up!
				# the client will have to setup a new preauth to begin recurring payments again
				# or pay using an alternative method
                logTransaction('GoCardless', 'No pre-authorisation found', 'Incomplete');
            }

        }

    }

    /**
	** Supress credit card request on checkout
	**/
    function gocardless_nolocalcc() {}

	/**
	** Create mod_gocardless table if it does not already exist
	**/
    function gocardless_createdb() {

        $query = "CREATE TABLE IF NOT EXISTS `mod_gocardless` (
                 `id` int(11) NOT NULL auto_increment,
                 `invoiceid` int(11) NOT NULL,
                 `billcreated` int(11) default NULL,
                 `resource_id` varchar(16) default NULL,
                 `preauth_id` varchar(16) default NULL,
                 `payment_failed` tinyint(1) NOT NULL default '0',
                 PRIMARY KEY  (`id`))";

        full_query($query);

    }
	
	/**
	** Display payment status message to admin when the preauth
	** has been setup but the payment is incomplete
	**/
    function gocardless_adminstatusmsg($vars) {

        if ($vars['status']=='Unpaid') {

            # get relevant invoice information from the database
            $d = select_query('mod_gocardless',"id,payment_failed",array('invoiceid' => $vars['invoiceid']));
            $aResult = mysql_fetch_assoc($d);
            
            # check we have been able to obtain the details
            if($aResult['id']) {
                if($aResult['payment_failed']) {
                    # if the payment failed flag is set, notify the admin of this problem
                    return array('type' => 'error', 'title' => 'GoCardless Payment Failed', 'msg' => 'One or more payments against this invoice have failed. By default, GoCardless will not attempt to make another payment.');
                } else {
                    # the record exists in the database, the invoice is unpaid and the payment hasnt failed
                    # this condition means that the payment must be pending!
                    return array('type' => 'info', 'title' => 'GoCardless Payment Pending', 'msg' => 'There is a pending payment already in processing for this invoice. Status will be automatically updated once confirmation is received back from GoCardless.' );
                }
            }
            unset($d,$aResult);
        }

    }
