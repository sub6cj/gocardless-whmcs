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
require_once $whmcsdir . '/modules/gateways/gocardless.php';

$gateway = getGatewayVariables('gocardless');

if ( ! $gateway['type']) {
  die("Module Not Activated");
}

GoCardless::set_account_details(array(
  'app_id'        => $gateway['app_id'],
  'app_secret'    => $gateway['app_secret'],
  'merchant_id'   => $gateway['merchant_id'],
  'access_token'  => $gateway['access_token'],
  'ua_tag'        => 'gocardless-whmcs/v' . GC_WHMCS_VERSION
));

if (isset($_GET['resource_id']) && isset($_GET['resource_type'])) {

  $confirmed_resource = GoCardless::confirm_resource(array(
    'resource_id'   => $_GET['resource_id'],
    'resource_type' => $_GET['resource_type'],
    'resource_uri'  => $_GET['resource_uri'],
    'signature'     => $_GET['signature'],
    'state'         => $_GET['state']
  ));

  $gc_invoice_data = explode(':', $_GET['state']);
  $gc_invoice = array(
    'id'      => $gc_invoice_data[0],
    'amount'  => $gc_invoice_data[1],
  );

  if ($gc_invoice['id']) {

    $invoiceid = $gc_invoice['id'];

    $d = select_query('tblinvoiceitems', 'relid, userid', array('type' => 'Hosting', 'invoiceid' => $invoiceid));

    while ($res = mysql_fetch_assoc($d)) {

      update_query('tblhosting', array('subscriptionid' => $_GET['resource_id']), array('id' => $res['relid']));

      $d2 = select_query('tblclients', 'gatewayid', array('id' => $res['userid']));
      $res2 = mysql_fetch_assoc($d2);

      if ($res2['gatewayid'] == '') {
        update_query('tblclients', array('gatewayid' => 'gocardless'), array('id' => $res['userid']));
      }

    }

    switch ($_GET['resource_type']) {

      case "pre_authorization":

        // This is a pre_auth, we need to create a bill
        $pre_auth = GoCardless_PreAuthorization::find($_GET['resource_id']);
        $bill = $pre_auth->create_bill(array('amount' => $gc_invoice['amount']));

        if ($bill->id) {
          insert_query('mod_gocardless', array('invoiceid' => $invoiceid, 'billcreated' => 1, 'resource_id' => $bill->id));
        }

        break;

      default:

        insert_query('mod_gocardless', array('invoiceid' => $invoiceid, 'billcreated' => 1, 'resource_id' => $_GET['resource_id']));

        break;

    }

  }

  $url = ($CONFIG['SystemSSLURL'] ? $CONFIG['SystemSSLURL'] : $CONFIG['SystemURL']);
  $redirecturl = $url . '/viewinvoice.php?id=' . $invoiceid;
  header('Location: ' . $redirecturl);

}
