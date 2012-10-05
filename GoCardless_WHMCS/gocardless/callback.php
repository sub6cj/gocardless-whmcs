<?php

/**
 * GoCardless WHMCS module
 *
 * @author WHMCS <info@whmcs.com>
 * @version 0.1.0
 */

$whmcsdir = dirname(__FILE__) . '/../../../';
require_once $whmcsdir . 'dbconnect.php';
require_once $whmcsdir . '/includes/functions.php';
require_once $whmcsdir . '/includes/gatewayfunctions.php';
require_once $whmcsdir . '/includes/invoicefunctions.php';
require_once $whmcsdir . '/modules/gateways/gocardless.php';

$gateway = getGatewayVariables('gocardless');

if ( ! $gateway['type']) {
  die('Module not activated.');
}
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

$webhook = file_get_contents('php://input');
$webhook_array = json_decode($webhook, true);
$webhook_valid = GoCardless::validate_webhook($webhook_array['payload']);

if ($webhook_valid == true) {

  $val = $webhook_array['payload'];
  $resource_type = $val['resource_type'];
  $action = $val['action'];

  if ($action=="paid") {

  foreach ($webhook_array['payload']['bills'] as $key => $val) {

    $resourceid = $val['source_id'];
    $status = $val['status'];
    $transid = $val['id'];

    $query = "SELECT gc.invoiceid AS invoiceid, i.total AS total FROM tblinvoices i, mod_gocardless gc WHERE gc.invoiceid = i.id AND gc.resource_id = '".db_escape_string($transid)."'";
    $d = full_query($query);

    if (mysql_num_rows($d)) {

      $res = mysql_fetch_assoc($d);

      $invoiceid = $res['invoiceid'];
      $total = $res['total'];
      $fee = 0;

      $invoiceid = checkCbInvoiceID($invoiceid, $gateway['name']);
      checkCbTransID($transid);

      if ($status == 'paid') {

        addInvoicePayment($invoiceid, $transid, $total, $fee, 'gocardless');
        logTransaction($gateway['name'], print_r($val, 1), 'Successful');

      } else {

        logTransaction($gateway['name'], print_r($val, 1), 'Unsuccessful');

      }

    } else {

      $bill = GoCardless_Bill::find($transid);

      if ($status == 'paid') {

        $query = "SELECT tblinvoiceitems.invoiceid,tblinvoices.userid FROM tblhosting INNER JOIN tblinvoiceitems ON tblhosting.id = tblinvoiceitems.relid INNER JOIN tblinvoices ON tblinvoices.id = tblinvoiceitems.invoiceid WHERE tblinvoices.status = 'Unpaid' AND tblhosting.subscriptionid = '$resourceid' AND tblinvoiceitems.type = 'Hosting' ORDER BY tblinvoiceitems.invoiceid ASC";
        $d = full_query($query);

        $res = mysql_fetch_array($d);

        $invoiceid = $res['invoiceid'];
        $userid = $res['userid'];

        if ($invoiceid) {

          $mc_gross = $bill->amount;

          $m = "Invoice Found from Subscription ID Match => $invoiceid\n";
          logTransaction('GoCardless', $m , 'Successful');

          $currency = getCurrency($userid);

          $result = select_query('tblcurrencies', '', array('code' => 'GBP'));
          $data = mysql_fetch_assoc($result);

          $currencyid = $data['id'];
          $currencyconvrate = $data['rate'];

          if ($currencyid != $currency['id']) {
            $mc_gross = convertCurrency($mc_gross, $currencyid, $currency['id']);
            $mc_fee = 0;
          }

          addInvoicePayment($invoiceid, $transid, $mc_gross, $mc_fee, 'gocardless');

          $result = select_query('tblinvoiceitems', '', array('invoiceid' => $invoiceid, 'type' => 'Hosting'));
          $data = mysql_fetch_array($result);

          $relid = $data['relid'];
          update_query('tblhosting', array('subscriptionid' => $resourceid), array('id' => $relid));

          exit;

        }

      } else {

        $m .= print_r($val, 1);
        logTransaction($gateway['name'], $m, 'Incomplete');
        exit;

      }

    }

  }
  // end foreach

  } elseif ($action=="cancelled") {


    foreach ($webhook_array['payload']['pre_authorizations'] as $key => $val) {

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
