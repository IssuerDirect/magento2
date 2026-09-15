---
title: Stripe Payments, Billing, and Tax plugin for Adobe Commerce
subtitle: Enable Stripe payments for storefronts built on Adobe Commerce.
route: /use-stripe-apps/adobe-commerce/payments
redirects:
  - /magento
  - /plugins/magento
  - /plugins/magento/module-quickstart
  - /plugins/magento-2
  - /plugins/adobe-commerce
  - /plugins/magento/cryozonic
  - /magento/cryozonic
  - /connectors/adobe-commerce/payments
stripe_products:
  - payments
  - checkout
  - elements
  - billing
  - radar
target_locales: ['es-ES', 'fr-CA', 'it-IT']
---

[Install the module](/use-stripe-apps/adobe-commerce/payments/install) and then go to the [configuration](/use-stripe-apps/adobe-commerce/payments/configuration) section to set your preferred checkout flow and other options.

## Accept online payments {% #payments %}

The module offers two different flows for accepting payments in Adobe Commerce:

- Embed the [Payment Element](/payments/payment-element) on your website (recommended).
- Redirect to [Stripe Checkout](/payments/checkout), a payment form hosted on Stripe. The redirect flow supports [Adaptive Pricing](/payments/checkout/adaptive-pricing), which lets customers pay in their local currency while you settle in your Stripe account's currency.

Both options are optimized for conversion and SAQ-A eligible, simplifying {% glossary term="pci-compliance" %}PCI compliance{% /glossary %}.

{% image
   src="images/adobe-commerce/embedded-flow.png"
   width=70
   ignoreAltTextRequirement=true %}
Embed the Payment Element
{% /image %}

{% image
   src="images/adobe-commerce/redirect-flow.png"
   width=70
   ignoreAltTextRequirement=true %}
Redirect to Stripe Checkout
{% /image %}

You can individually enable or disable payment methods from your [payment methods settings](https://dashboard.stripe.com/settings/payment_methods). This applies to both Stripe Checkout and the Payment Element. You don't need to upgrade your integration after you enable a payment method, even if the payment method became available after you installed the plugin.

{% callout %}
The full list of supported payment methods is available in the [Payment method support](/payments/payment-methods/payment-method-support) guide.
{% /callout %}

To optimize conversions, Stripe Checkout and the Payment Element display payment methods dynamically to adapt to the current session. The customer device, shipping country, cart currency and even cart contents are taken into consideration to select and sort payment methods for conversion. For logged-in customers, we display their saved payment methods first to enable faster checkout.

You can customize the look and feel of the Payment Element by overriding the `getElementOptions()` PHP method under `Model/Ui/ConfigProvider.php`. To do this, implement an [afterMethod plugin](https://developer.adobe.com/commerce/php/development/components/plugins/#after-methods).


### Customer Authentication {% #sca %}

{% callout type="note" %}
The plugin is [SCA-ready](/strong-customer-authentication) and includes {% glossary term="3d-secure-2" %}3D Secure 2{% /glossary %} support for customer authentication.
{% /callout %}

By default, customers only see 3D Secure authentication when their bank requires it, so your checkout conversion isn't negatively affected. In compliance with the {% glossary term="sca" %}Strong Customer Authentication{% /glossary %} regulation, Stripe displays the 3D Secure authentication flow automatically whenever required by SCA:

{% image
   src="images/adobe-commerce/3d-secure.png"
   width=70
   ignoreAltTextRequirement=true %}
Stripe provides a 3D Secure test payment page in a sandbox
{% /image %}

You can configure your 3DS preferences in your [Radar rules](https://dashboard.stripe.com/settings/radar/rules).

To test the authentication flow, switch the module to a sandbox and place an order using any of the [test card numbers](/payments/3d-secure/authentication-flow#three-ds-cards).

## Grow your recurring revenue with subscriptions {% #subscriptions %}

Our module offers a subscription engine for Adobe Commerce that includes the following features:

- Configurable and customer-customizable subscription products in your catalog pages.
- Trial plans or the ability to collect initial fees with each subscription purchase.
- Customer notifications and the collection of new payment details from Stripe Billing when subscription payments fail.
- Reduced churn because Stripe works directly with card networks to automatically update payment details with new card numbers or expiry dates.

## Translations for multi-language websites

{% callout type="caution" %}
If you configure your locale or currency for the first time, make sure to [flush the configuration cache](https://devdocs.magento.com/guides/v2.3/config-guide/cli/config-cli-subcommands-cache.html#config-cli-subcommands-cache-clean).
{% /callout %}

The module contains a translation file that you can use to configure a multi-language Adobe Commerce site:

```ode
/app/code/StripeIntegration/Payments/i18n/en_US.csv
```

To create a translation file for a different language, copy this file to:

```ode
/app/code/StripeIntegration/Payments/i18n/languagecode_COUNTRYCODE.csv
```

Make sure to replace `languagecode_COUNTRYCODE` with the locale code for your target language. This is the same language you've selected under **System > Configuration > General > Locale Options > Locale**.

After you copy the file, you can replace the second string on each row with a translation of the first string. You don't need to do anything else for translations.

{% see-also %}
- [Enable subscriptions for Adobe Commerce products](/use-stripe-apps/adobe-commerce/payments/subscriptions)
- [Use the Adobe Commerce admin panel](/use-stripe-apps/adobe-commerce/payments/admin)
- [Build a custom storefront](/use-stripe-apps/adobe-commerce/payments/custom-storefront)
- [Troubleshooting](/use-stripe-apps/adobe-commerce/payments/troubleshooting)
{% /see-also %}
