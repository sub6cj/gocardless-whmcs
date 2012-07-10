<?php
/**
 * GoCardless WHMCS module
 *
 * @author WHMCS <info@whmcs.com>
 * @version 0.1.0
 */

require_once ROOTDIR . '/modules/gateways/gocardless/GoCardless.php';

define('GC_VERSION', '0.1.0');

function gocardless_config() {

  $configarray = array(
    'FriendlyName'  => array('Type' => 'System', 'Value' => 'GoCardless'),
    'merchant_id'   => array('FriendlyName' => 'Merchant ID', 'Type' => 'text', 'Size' => '15', 'Description' => '<a href="http://gocardless.com/merchants/new">Sign up</a> for a GoCardless account then find your API keys in the Developer tab'),
    'app_id'        => array('FriendlyName' => 'App ID', 'Type' => 'text', 'Size' => '100'),
    'app_secret'    => array('FriendlyName' => 'App Secret', 'Type' => 'text', 'Size' => '100'),
    'access_token'  => array('FriendlyName' => 'Access Token', 'Type' => 'text', 'Size' => '100'),
    'oneoffonly'    => array('FriendlyName' => 'One Off Only', 'Type' => 'yesno', 'Description' => 'Tick to only perform one off captures - no recurring pre-auth agreements'),
    'instantpaid' => array('FriendlyName' => 'Instant Activation', 'Type' => 'yesno', 'Description' => 'Tick to immediately mark invoices paid after payment is initiated (despite clearing not being confirmed for 3-5 days)', ),
    'testmode' => array('FriendlyName' => 'Test Mode', 'Type' => 'yesno', 'Description' => 'Tick to enable test mode', ),
  );

  return $configarray;

}

function gocardless_link($params) {

  global $CONFIG;

  // Create GoCardless Payment Status Table on First Use
  gocardless_createdb();

  // Check for Pending Payment
  $pendingid = get_query_val('mod_gocardless', 'id', array('invoiceid' => $params['invoiceid'], 'resource_id' => array('sqltype' => 'NEQ', 'value' => '')));

  if ($pendingid) {

    // Pending Payment Found - Prevent Duplicate Payment with a Msg
    return '<strong>Your payment is currently pending and will be processed within 3-5 days.</strong>';

  } else {

    // Get Tax Rates
    $data = get_query_vals("tblinvoices", "taxrate,taxrate2", array('id' => $params['invoiceid']));
    $taxrate = $data["taxrate"];
    $taxrate2 = $data["taxrate2"];
    $taxrate = (!$taxrate && $CONFIG['TaxType'] == 'Inclusive') ? 1 : ($taxrate / 100) + 1;

    $maxamount = $recurfrequency = 0;

    // Check if Recurring is Disabled
    if ( ! $params['oneoffonly']) {

        // Calculate Max Required Amount for Pre-Auth
        $result = select_query("tblinvoiceitems", "type,relid,amount,taxed", array('invoiceid' => $params['invoiceid']));
        while ($data = mysql_fetch_array($result)) {

            $itemtype   = $data['type'];
            $itemrelid  = $data['relid'];
            $itemamount = $data['amount'];
            $itemtaxed  = $data['taxed'];

            $itemtaxed = ($itemtaxed) ? $taxrate : 1;

            $itemrecurvals = array();
            if ($itemtype == 'Hosting') $itemrecurvals = get_query_vals("tblhosting", "firstpaymentamount,amount,billingcycle", array('id' => $itemrelid));
            if ($itemtype == 'Addon') $itemrecurvals = get_query_vals("tblhostingaddons", "(setupfee+recurring),recurring,billingcycle", array('id' => $itemrelid));
            if ($itemtype == 'DomainRegister' || $itemtype == 'DomainRegister') $itemrecurvals = get_query_vals("tbldomains", "firstpaymentamount,recurringamount", array('id' => $itemrelid));

            if (count($itemrecurvals) && $itemrecurvals[2] != 'One Time' && $itemrecurvals[2] != 'Free Account' && $itemrecurvals[2] != 'Free') {

                // Add to Recurring Amount
                $maxamount += ($itemrecurvals[0] > $itemrecurvals[1]) ? $itemrecurvals[0] * $itemtaxed : $itemrecurvals[1] * $itemtaxed;

                // Also track recurring months
                $recurmonths = getBillingCycleMonths($itemrecurvals[2]);
                if ( ! $recurfrequency || $recurmonths < $recurfrequency) {
                  $recurfrequency = $recurmonths;
                }

            }

        }

    }

    // Initialise Account Details
    GoCardless::set_account_details(array(
      'app_id'        => $params['app_id'],
      'app_secret'    => $params['app_secret'],
      'merchant_id'   => $params['merchant_id'],
      'access_token'  => $params['access_token'],
      'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
    ));

    $user = array(
      'first_name'        => $params['clientdetails']['firstname'],
      'last_name'         => $params['clientdetails']['lastname'],
      'email'             => $params['clientdetails']['email'],
      'billing_address1'  => $params['clientdetails']['address1'],
      'billing_address2'  => $params['clientdetails']['address2'],
      'billing_town'      => $params['clientdetails']['city'],
      'billing_county'    => $params['clientdetails']['state'],
      'billing_postcode'  => $params['clientdetails']['postcode'],
    );

    if ( ! $maxamount) {
      // One Off Payment

      $title = 'Pay Now with GoCardless';
      $url = GoCardless::new_bill_url(array(
        'amount'  => $params['amount'],
        'name'    => $params['description'],
        'user'    => $user,
        'state'   => $params['invoiceid'] . ':' . $params['amount']
      ));

      // Return One Time Payment Button
      return '<a href="'.$url.'"><input type="button" value="'.$title.'" /></a>';

    } else {
      // Or Recurring Amount

      $title = 'Create Subscription with GoCardless';
      $url = GoCardless::new_pre_authorization_url(array(
        'max_amount'      => $maxamount,
        'interval_length' => $recurfrequency,
        'interval_unit'   => 'month',
        'user'            => $user,
        'state'           => $params['invoiceid'] . ':' . $params['amount']
      ));

      // Return Recurring Pre-Auth Button
      return 'When you get to GoCardless you will see an agreement for the <b>maximum possible amount</b> we\'ll ever need to charge you in a single invoice for this order, with a frequency of the shortest item\'s billing cycle. But rest assured we will never charge you more than the actual amount due.
        <br /><a href="'.$url.'"><input type="button" value="'.$title.'" /></a>';

    }

  }

}

function gocardless_capture($params) {

  gocardless_createdb();

  GoCardless::set_account_details(array(
    'app_id'        => $params['app_id'],
    'app_secret'    => $params['app_secret'],
    'merchant_id'   => $params['merchant_id'],
    'access_token'  => $params['access_token'],
    'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
  ));

  $existing_payment_query = select_query('mod_gocardless', 'resource_id', array('invoiceid' => $params['invoiceid']));
  $existing_payment = mysql_fetch_assoc($existing_payment_query);

  if ( ! mysql_num_rows($existing_payment_query) || empty($existing_payment['resource_id'])) {

    $invoice_item_query = select_query('tblinvoiceitems', 'relid', array('invoiceid' => $params['invoiceid'], 'type' => 'Hosting'));

    while ($invoice_item = mysql_fetch_assoc($invoice_item_query)) {

      $package_query = select_query('tblhosting', 'subscriptionid', array('id' => $invoice_item['relid']));
      $package = mysql_fetch_assoc($package_query);

      if ( ! empty($package['subscriptionid'])) {
        $preauthid = $package['subscriptionid'];
      }

    }

    if (isset($preauthid)) {

      $pre_auth = GoCardless_PreAuthorization::find($preauthid);
      $bill = $pre_auth->create_bill(array('amount' => $params['amount']));

      if ($bill->id) {

        if ( ! mysql_num_rows($existing_payment_query)) {
          insert_query('mod_gocardless', array('invoiceid' => $params['invoiceid'], 'billcreated' => 1, 'resource_id' => $bill->id));
          logTransaction('GoCardless', 'Transaction initiated successfully, confirmation will take 2-5 days', 'Pending');
        } else {
          update_query('mod_gocardless', array('billcreated' => 1, 'resource_id' => $bill->id), array('invoiceid' => $params['invoiceid']));
        }

      }

    } else {

      logTransaction('GoCardless', 'No pre-authorisation found', 'Incomplete');

    }

  }

}

// Supress credit card request on checkout
function gocardless_nolocalcc() {}

function gocardless_createdb() {

  $query = "CREATE TABLE IF NOT EXISTS `mod_gocardless` (
        `id` int(11) NOT NULL auto_increment,
        `invoiceid` int(11) NOT NULL,
        `billcreated` int(11) default NULL,
        `resource_id` varchar(16) default NULL,
        PRIMARY KEY  (`id`))";

  full_query($query);

}

function gocardless_initiatepayment() {

}

function gocardless_adminstatusmsg($vars) {

    if ($vars['status']=='Unpaid') {

        $refid = get_query_val("mod_gocardless","id",array("invoiceid"=>$vars['invoiceid']));

        if ($refid) return array('type' => 'info', 'title' => 'GoCardless Payment Pending', 'msg' => 'There is a pending payment already in processing for this invoice. Status will be automatically updated once confirmation is received back from GoCardless.' );

    }

}
