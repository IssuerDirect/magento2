---
title: Use the Stripe app for Adobe Commerce (Magento 2)
subtitle: Learn how to install, upgrade, and uninstall the Stripe app for Adobe Commerce (Magento 2).
route: /use-stripe-apps/adobe-commerce/payments/install
redirects:
  - /magento/install
  - /plugins/magento/install
  - /plugins/magento-2/install
  - /plugins/adobe-commerce/install
  - /use-stripe-apps/adobe-commerce/install
  - /connectors/adobe-commerce/payments/install
  - /use-stripe-apps/adobe-commerce/payments/versions
  - /use-stripe-apps/adobe-commerce/versions
  - /connectors/adobe-commerce/payments/versions
stripe_products: []
target_locales: ['es-ES', 'fr-CA', 'it-IT']
---

{% callout type="caution" %}
We recommend that you test the module before installing it in your production environment. If you have an installation issue, see [Troubleshooting](/use-stripe-apps/adobe-commerce/payments/troubleshooting).
{% /callout %}

## Install the module {% #install %}

{% tabs pref="integration" defaultValue="marketplace" %}

{% tab title="From the Marketplace (recommended)" id="marketplace" %}

1. Place an order for the module through the [Adobe Marketplace](https://marketplace.magento.com/stripe-stripe-payments.html).

1. Open a terminal and run the following command in your Adobe Commerce directory:

    ```bash
    composer require stripe/stripe-payments
    ```

At this stage, you might have to submit your username and password. Provide your [Adobe Commerce authentication keys](https://devdocs.magento.com/guides/v2.3/install-gde/prereq/connect-auth.html). You can accept to save your credentials when prompted by Composer. If you've saved your keys and see the error `Invalid Credentials`, update your keys in `~/.composer/auth.json` or delete this file and run the command again.

1. Set up the module by running the following commands:

    ```bash
    php bin/magento setup:upgrade
    php bin/magento cache:flush
    php bin/magento cache:clean
    ```

1. If you run Adobe Commerce in production mode, you must also compile and deploy the module's static files.

```bash
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
```
{% /tab %}

{% tab title="From the raw package" id="package" %}

1. Download the [latest version](https://github.com/stripe/stripe-magento2-releases/raw/master/stripe-magento2-latest.tgz) of the module from Stripe.

1. Extract the module in your Adobe Commerce directory.

    ```bash
    tar -xvf stripe-magento2-latest.tgz
    ```

1. Install the Stripe PHP library.

    ```bash
    composer require stripe/stripe-php:~20.1
    ```

1. Set up the module.

    ```bash
    php bin/magento setup:upgrade
    php bin/magento setup:di:compile
    php bin/magento cache:flush
    ```

1. If you run Adobe Commerce in production mode, you must also compile and deploy the module's static files.

    ```bash
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy
    ```
{% /tab %}

{% /tabs %}

## Upgrade the module {% #upgrade %}

Before you upgrade:

- Back up your files and database.
- Start with your sandbox environment.
- Keep a copy of any customization you made to the module's original code.
- Check out the [CHANGELOG](https://github.com/stripe/stripe-magento2-releases/blob/master/CHANGELOG.md).

Patch releases (x.x.Y) are backward compatible and require no extra development on your side after you upgrade. Minor and major releases might add new features or change code in a backwards incompatible way. If you customized the module's code, you'll need to port these customizations after upgrading and resolve any potential conflict.

{% tabs pref="integration" %}

{% tab title="From the Marketplace" id="marketplace" %}

Run the following commands:

```bash
composer require -W stripe/stripe-payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:clean
```
{% /tab %}

{% tab title="From the raw package" id="package" %}

Run the following commands:

```bash
php bin/magento module:disable --clear-static-content StripeIntegration_Payments
rm -rf app/code/StripeIntegration/Payments
tar -xvf stripe-magento2-latest.tgz
php bin/magento module:enable StripeIntegration_Payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:flush
php bin/magento cache:cleanStripeIntegration_Payments
```
{% /tab %}

{% /tabs %}

## Uninstall the module {% #uninstall %}

Before you uninstall:

- Backup your files and database.
- Keep a copy of any customization you made to the module's original code in case you need to reinstall it later.

{% tabs pref="integration" %}

{% tab title="From the Marketplace" id="marketplace" %}

Run the following commands:

```bash
composer remove stripe/stripe-payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:clean
```
{% /tab %}

{% tab title="From the raw package" id="package" %}

Run the following commands:

```bash
php bin/magento module:disable --clear-static-content StripeIntegration_Payments
composer remove stripe/stripe-php
rm -rf app/code/StripeIntegration/Payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:flush
php bin/magento cache:clean
```
{% /tab %}

{% /tabs %}

## Lifecycle policy

The latest version of the module supports Adobe Commerce 2.4.2 and later.

For `stripe/stripe-payments:4.6.*` and later, we provide bug fixes and security patches. Stripe recommends that you upgrade to the latest published version of the module. All releases are available in the Adobe Marketplace and in the [stripe-magento2-releases](https://github.com/stripe/stripe-magento2-releases) GitHub repository.

## Version history

Learn about backward compatibility changes in Adobe Commerce app versions (Magento 2).

### Version 4.6

#### New features and enhancements

- Adaptive Pricing support for the redirect flow (Stripe Checkout): when enabled, Stripe automatically presents prices in the customer's local currency and settles in the merchant's account currency. The admin order view shows the amount the customer was charged, and an order comment is added when the presentment currency differs from the session currency. Currency conversion fees are covered by the customer, rather than the merchant.

- Payment method collection now uses confirmation tokens across all flows (onepage checkout, multishipping, customer account), providing enhanced support for 40+ payment methods and stronger 3D Secure handling.

- Saved payment methods are now displayed inside the Payment Element itself, and can be updated or deleted in-place without leaving the checkout.

- Explicit customer consent for saving payment methods. The "Save customer payment method" setting now has three options: "Disabled", "Ask for customer consent" (displays a checkbox on the Payment Element) and "Save without asking" (the previous silent behavior). See the Breaking changes section below for migration details.

- Asynchronous refunds support. For payment methods that settle refunds asynchronously (such as ACH Direct Debit and SEPA Direct Debit), credit memos are created in an Open state and are finalized or cancelled by a `refund.updated` webhook when the refund succeeds or fails. Merchants receive an email notification on failed refunds.

- Strict Content Security Policy (CSP) mode is now fully supported. Inline scripts have been rewritten across the Express Checkout, multishipping, customer payment methods and subscription payment method change pages.

- Customers can add a new payment method directly from the "My Subscriptions" page and assign it to a subscription in a single step, without first adding it from the "My Payment Methods" page.

- The shipping address, quantities and product options of trialing subscriptions and future start date subscriptions can now be edited by the customer before the subscription starts.

- Configurable email notifications for subscription renewals and subscription changes, with support for custom email templates.

- CVC recollection for saved cards now works on the multishipping checkout.

- Configurable "Pending Payment Order Lifetime" for Bank Transfers and for the admin "Invoice via Stripe Billing" payment method. Orders placed with these methods can be automatically cancelled after the invoice becomes overdue by the configured number of days.

- Stripe Invoicing (the admin "Invoice via Stripe Billing" payment method) now has its own configuration section with an Enabled toggle and the Pending Payment Order Lifetime setting.

- Replaced Card Element in the admin area with Payment Element. Technical change with no changes to available payment methods.

- Improved payment form error messages at the multishipping checkout.

- Performance: when the Payment Method Messaging Element is disabled, its JavaScript is no longer loaded on catalog and product pages.

- Redirect-based payment methods (iDEAL, Klarna, Bancontact, Alipay, WeChat Pay, P24, EPS, BLIK, FPX, GiroPay, Sofort and others) no longer create a Pending invoice at order placement. Previously, when the customer was redirected to authenticate the payment, the order was placed in `Pending Payment` status alongside an invoice in `Pending` status. The invoice could not be cancelled while the order was in `Pending Payment`, which prevented merchants from cancelling abandoned orders without first cancelling the invoice. Starting with 4.6.0, no invoice is created until the customer completes the redirect; the invoice is then created in `Paid` status when the `charge.succeeded` webhook arrives. Merchants can now cancel `Pending Payment` orders directly from the admin while waiting for the customer to complete authentication.

- Support for PHP 8.5, which is now available in Magento 2.4.9.

- Upgraded Stripe API version to `2026-05-27.dahlia` and Stripe.js to the corresponding `dahlia` channel. The `apiVersion` parameter has been removed from the Stripe.js initialization options. See the Breaking changes section for migration details.

- Scalapay now supports Authorize Only mode, allowing merchants to authorize Scalapay payments and capture them later from the admin panel.

- Express Checkout Element wallet-button parameters (`allowedShippingCountries`, `billingAddressRequired`, `emailRequired`, `phoneNumberRequired`, `shippingAddressRequired`) are now set at element creation time instead of on the click event, aligning with the latest Stripe.js requirements.

#### New REST API endpoints

- `GET /V1/stripe/payments/get_stripe_ece_configuration` — Returns the Stripe configuration and Express Checkout Element button configuration for a given location (checkout, product, cart, minicart).
- `POST /V1/stripe/payments/change_subscription_payment_method` — Changes the default payment method on a Stripe subscription for the logged-in customer.
- `GET /V1/stripe/payments/get_checkout_session_url` — Returns the full Stripe Checkout session redirect URL for the active quote when an order has been placed, for redirecting the customer to Stripe's hosted checkout page. The front-end now uses `window.location.href` for redirection instead of `stripe.redirectToCheckout()`.

The existing `add_payment_method` endpoint now also accepts a confirmation token (`ctoken_*`) in addition to a payment method ID (`pm_*`).

#### REST API endpoint changes

- Deprecated and removed the unused `POST /V1/stripe/payments/cancel_last_order` API endpoint.
- Removed unused `$quoteId` parameter from `POST /V1/stripe/payments/place_multishipping_order`, `POST /V1/stripe/payments/finalize_multishipping_order`, and `POST /V1/stripe/payments/update_cart`.

#### New GraphQL mutations and queries

Mutations:

- `changeSubscriptionPaymentMethod(input: SubscriptionPaymentMethodInput!)` — GraphQL equivalent of the new REST endpoint for changing a subscription's default payment method.
- `getECEParams(input: ECEParamsRequest!)` — Returns the parameters required to initialize the Express Checkout Element (appearance, currency, amount, line items, shipping rates, address requirements).
- `addToCart(input: AddToCartECERequest!)` — Adds a product to the cart from the Express Checkout Element on product / cart / minicart.
- `eceShippingAddressChanged(input: ECEShippingAddressChange)` — Handles the shipping address change event fired by the Express Checkout Element and returns refreshed ECE params.
- `eceShippingRateChanged(input: ECEShippingRateChange)` — Handles the shipping rate change event and returns refreshed ECE params.
- `ecePlaceOrder(input: ECEPlaceOrder)` — Places an order originating from the Express Checkout Element, returning a redirect URL and/or a client secret for 3DS authentication.
- `restoreQuote` — Reactivates the last active quote after a customer returns from an external authentication or Stripe Checkout redirect.
- `updateCart(input: UpdateCartInput)` — Detects whether the current cart differs from the previously placed order after a payment failure; cancels the old order and signals that a new order should be placed if anything changed.
- `placeMultishippingOrder` — Places multiple orders (one per shipping address) under a single Stripe PaymentIntent for multishipping checkouts.
- `finalizeMultishippingOrder(error: String)` — Cancels pending multishipping orders on payment failure, or finalizes the checkout on success.

Queries:

- `getStripeECEConfiguration(input: ECEConfigurationInput)` — Returns whether the Express Checkout Element is enabled at the requested location along with its initialization parameters and button config.
- `getRequiresAction` — Returns the client secret of a PaymentIntent or SetupIntent that requires customer action, so the storefront can trigger authentication.
- `getFutureSubscriptions(input: FutureSubscriptionsInput)` — Returns subscription preview details (start date, frequency, formatted amount) for products with trials or future start dates in the active quote.
- `getCheckoutPaymentMethods(input: CheckoutPaymentMethodsInput!)` — Returns available Stripe Checkout payment methods after applying the billing address, shipping address, and coupon code to the quote.
- `getCheckoutSessionId` — Returns the Stripe Checkout session ID for the active quote when an order has been placed, for redirecting the customer to Stripe's hosted checkout page.
- `getCheckoutSessionUrl` — Returns the Stripe Checkout session redirect URL for the active quote when an order has been placed, for redirecting the customer to Stripe's hosted checkout page.
- `getUpcomingInvoice` — Previews the upcoming invoice when a subscription is being updated, showing the new price, credits, or an error.
- `getInstallmentPlans` — Returns available installment plan options stored in the session during order placement for card installments.

Input / type changes:

- `StripePaymentsInput.confirmation_token` added — preferred way to pass the payment method from headless storefronts.
- `ModuleConfiguration.appInfo` is now a structured `AppInfo` object (`name`, `partner_id`, `url`, `version`) instead of a flat `[String]`.
- `FutureSubscriptionsInput`, `CheckoutPaymentMethodsInput`, `UpdateCartInput` added — new GraphQL input types enabling the new queries and mutations.
- `FutureSubscriptionsOutput`, `CheckoutPaymentMethodsOutput`, `UpcomingInvoiceOutput`, `RestoreQuoteOutput`, `UpdateCartOutput`, `PlaceMultishippingOrderOutput`, `FinalizeMultishippingOrderOutput` added — new GraphQL output types for the new endpoints.
- `ExpressCheckoutOptions` type added to the GraphQL schema, containing the wallet-button parameters (`allowedShippingCountries`, `billingAddressRequired`, `emailRequired`, `phoneNumberRequired`, `shippingAddressRequired`). Available as `expressCheckoutOptions` on the `ECEParams` type returned by the `getECEParams` query.

A new headless example demonstrating Express Checkout Element placement has been added under `resources/examples/GraphQL/ExpressCheckout/`, and the existing `PaymentElement` examples have been updated to use confirmation tokens.

#### Breaking changes

**Billing address phone number is required.** The telephone field must not be disabled in the Magento admin (Stores > Configuration > Customer Configuration > Name and Address Options). Payments will be rejected if no phone number is provided. Previously, the phone number was optional for some payment methods.

**`save_payment_method` setting values have changed.** The setting was previously a boolean (`0` = Disabled, `1` = Enabled, which saved silently). It is now a three-value select:

- `0` — Disabled
- `1` — Ask for customer consent (a checkbox is shown on the Payment Element)
- `2` — Save without asking (equivalent to the previous "Enabled" behavior)

Stores that were saving payment methods silently (value `1`) will start showing a consent checkbox after upgrading. To preserve the previous behavior, reconfigure the setting to value `2`.

**The standalone CVC Element must be removed from custom front-end implementations.** The Payment Element now collects CVC for saved cards directly within the payment form. Any customization or template override that renders a separate CVC Element alongside a saved card selector must be removed. Leaving it in place will no longer have any effect and may break the checkout flow. Standard checkouts are unaffected.

**GraphQL: `cvc_token` is ignored.** The `StripePaymentsInput.cvc_token` field is kept in the schema for backwards compatibility but is no longer processed. Headless storefronts that relied on it for CVC recollection on saved cards must migrate to confirmation tokens (`StripePaymentsInput.confirmation_token`), which carry the recollected CVC automatically.

**GraphQL: `ModuleConfiguration.appInfo` type changed.** The field type changed from `[String]` to the structured `AppInfo` object (`name`, `partner_id`, `url`, `version`). Clients that parse this field must be updated.

**Stripe PHP SDK**: Upgraded from v17.6 to v20.1. The upgrade did not require changes to the codebase, however customizations that use additional Stripe API functionality should cross reference with https://github.com/stripe/stripe-php/blob/v20.1.0/CHANGELOG.md.

**Stripe.js URL changed.** The module now loads Stripe.js from `https://js.stripe.com/dahlia/stripe.js` instead of `https://js.stripe.com/v3/`. Custom storefronts that load Stripe.js directly must update the script source to match. The new URL corresponds to Stripe API version `2026-05-27.dahlia`.

**GraphQL: `ModuleOptions.apiVersion` field removed.** The `apiVersion` field has been removed from the `ModuleOptions` type (the `options` field in the `getStripeConfiguration` query). Custom storefronts reading this field must be updated. The Stripe API version is now managed server-side only and is no longer exposed to the client.

**Discount coupon property restructured.** With the upgraded Stripe API (`2026-05-27.dahlia`), the coupon property on subscription discounts moved from `discounts[0].coupon` to `discounts[0].source.coupon`. Custom integrations that inspect subscription discount objects must update their property access.

**`elements.update()` now returns a Promise.** Previously, the method behaved synchronously and required a manual `setTimeout` workaround to ensure the Elements UI had updated before proceeding. It now returns a Promise, so custom JavaScript that calls `elements.update()` should await the result or chain `.then()` handlers. Calls that rely on the old synchronous behavior may execute out of order.

**Express Checkout Element wallet-button params moved from `resolvePayload` to `expressCheckoutOptions`.** The `allowedShippingCountries`, `billingAddressRequired`, `emailRequired`, `phoneNumberRequired`, and `shippingAddressRequired` parameters in the `getECEParams` response have been moved out of the `resolvePayload` object and into the new top-level `expressCheckoutOptions` object. These params are no longer valid on the click event — they must be provided at element creation time. Custom storefronts and headless integrations that read these params from `resolvePayload` must update their code to read them from `expressCheckoutOptions` instead, and pass them to the `elements.create('expressCheckout', ...)` options (or to `expressCheckoutElement.update()`).

### Version 4.1

Version 4.1.0 introduces changes to handle scenarios where orders could be placed and invoiced without collecting payment. These scenarios include:

- Purchases of trial subscriptions.
- Subscriptions with future start dates.
- Upgrades or downgrades of existing subscription plans.

Previously, the module automatically refunded and closed these orders with an offline credit memo. Now, to improve tax reporting:

- Automatic refunds are deprecated.
- Order items in these scenarios have a custom price of 0.
- These items are excluded from shipping amount calculations.

This results in orders with zero subtotal, shipping, and tax amounts. When regular products are purchased with subscriptions, the subtotal, shipping, and tax amounts only reflect the regular order items. These changes ensure the order grand total matches the collected payment amount on the order date.

Additionally, version 4.1 changes how partial captures and refunds are handled:

- Previously, partial captures or refunds from the Stripe dashboard automatically created invoices or credit memos in Magento.
- These documents didn't include order items, using manual totals or adjustment fees or refunds instead.
- This approach caused issues with tax reporting.

In version 4.1:

- Only full captures and full refunds result in automatic invoices or credit memos.
- For partial captures or refunds, merchants should use the Magento admin panel.
- This ensures correct tax breakdown on documents and accurate reporting to the Stripe API.

{% see-also %}
* [Configuring the Stripe app for Adobe Commerce](/use-stripe-apps/adobe-commerce/payments/configuration)
* [Using the Adobe Commerce admin panel](/use-stripe-apps/adobe-commerce/payments/admin)
* [Troubleshooting](/use-stripe-apps/adobe-commerce/payments/troubleshooting)
{% /see-also %}
