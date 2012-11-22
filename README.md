![GoCardless](https://gocardless.com/resources/logo.png)

## GoCardless WHMCS Module

The GoCardless WHMCS module provides a simple way to use GoCardless from within WHMCS.

## Requirements

You must have WHMCS version 5.1.2 or later to use this WHMCS module.

## Getting started

1. [Download](https://github.com/gocardless/gocardless-whmcs/zipball/master) the latest version of the module.
2. Unzip the downloaded archive
3. Copy the contents of the GoCardless_WHMCS directory into `your_whmcs_install/modules/gateways` so that it replaces the existing version
4. Follow our [dedicated guide](https://gocardless.com/partners/whmcs) to using the module on the GoCardless site

## Support

For help with using this module, contact the GoCardless support team at <help@gocardless.com>.

## Changelog

__v1.0.5__

* Adds a description to bills created against a pre-auth with the invoice number
* Allows subsequent bills after the first one to be instantly marked as 'paid'
where the relevant setting is enabled

__v1.0.4__

* Fixes an issue where the first payment amount differs from the recurring amount
* Improves support for WHMCS installations with SSL enabled
* Improves logging where WHMCS tries to capture payment for an invoice which
already has a pending bill on GoCardless

__v1.0.3__

* Fixes an issue with connection to our SSL service experienced on some environments

__v1.0.2__

* Fixes issue with the GoCardless payment button in Internet Explorer - only affects a subset of WHMCS installations

__v1.0.1__

* Updates the WHMCS cron so any issues whilst collecting do not affect other payment gateways

__v1.0__

* Adds support for setup fees, allowing one-off products at the beginning of a recurring package
* Support for GoCardless Sandbox mode
* Added "Instant Activation" option to mark payments as paid straight away, rather than waiting for feedback from GoCardless
* Reliability improvements and bug fixes

__v.01__

* Initial release