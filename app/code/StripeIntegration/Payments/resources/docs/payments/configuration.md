---
title: Configure the Stripe Plugin for Adobe Commerce
subtitle: Set up payment methods and other options using the plugin.
route: /use-stripe-apps/adobe-commerce/payments/configuration
redirects:
  - /magento/configuration
  - /plugins/magento/configuration
  - /plugins/magento-2/configuration
  - /plugins/adobe-commerce/configuration
  - /connectors/adobe-commerce/payments/configuration
stripe_products: []
target_locales: ['es-ES', 'fr-CA', 'it-IT']
---

To configure the [plugin](/use-stripe-apps/adobe-commerce/payments) go to the configuration section for it (**Stores > Configuration > Sales > Payment Methods**):

{% image
   src="images/adobe-commerce/configure-module.png"
   width=70
   ignoreAltTextRequirement=true %}
Configuring the Stripe module
{% /image %}

Stripe appears on your checkout page only after you configure your API keys. If you don't have a Stripe account yet, [register](https://dashboard.stripe.com/register) online.

## Install the Adobe Commerce app from the Stripe App Marketplace {% #install-the-stripe-adobe-commerce-app %}

Install the app for Adobe Commerce from the Stripe App Marketplace to acquire the newly generated secret and publishable keys. This process bolsters the security of your plugin by simplifying the use of distinct restricted keys for each integration with your Stripe account. This approach eliminates the need to manually create your own restricted key or use a secret key. To install the app and reinforce your account's security infrastructure:

1. Navigate to the [Stripe App Marketplace](https://marketplace.stripe.com/), then click [Install the Adobe Commerce app](https://marketplace.stripe.com/apps/install/link/com.stripe.AdobeCommerce).
1. Select the Stripe account where you want to install the app.
1. Review and approve the app permissions, install the app in a {% glossary term="sandbox" %}sandbox{% /glossary%} or in live mode, then click **Install**.
1. After you install the app, store the keys in a safe place where you won't lose them. To help yourself remember where you stored them, you can [leave a note on the key in the Dashboard](/keys#reveal-an-api-secret-key-live-mode).
1. Use the newly generated publishable key and secret key to finish the plugin configuration.
1. To manage the app or generate new security keys after installation, go to the application settings page in a sandbox or in live mode.

## General settings {% #general-settings %}

- **Mode:** We recommend that you start by testing the integration in a sandbox. Switch to live mode when you're ready to accept live transactions. Learn more about [sandboxes](/sandboxes).
- **API keys:** Fill in the test and live keys that Stripe provides to you in the [Adobe Commerce app](https://dashboard.stripe.com/settings/apps/com.stripe.AdobeCommerce).
- **Hold Elevated Risk Orders:** If Stripe {% glossary term="radar" %}Radar{% /glossary %} marks a payment with an `Elevated Risk` status, the module places the order `On Hold` until you review the payment. See [Enabling fraud prevention features with Stripe Radar](/use-stripe-apps/adobe-commerce/payments/fraud-disputes#radar) for additional details.
- **Receipt Emails:** When enabled, Stripe sends a payment receipt email to the customer after the payment succeeds. You can customize the styles and brand of emails from your Stripe account settings.

## Payments {% #payments %}

- **Enabled:** Enable or disable Stripe as an available payment method for the standard checkout page, for the multi-shipping checkout page, and for the admin area.
- **Payment flow:** Select your preferred payment flow for the standard checkout page. With the embedded payment flow, we embed an iframe-based Payment Element directly in the checkout page. With the redirect payment flow, we redirect customers to Stripe Checkout to complete their payment.
- **Adaptive Pricing:** *(Redirect flow only)* When enabled, Stripe Checkout automatically detects the customer's location and presents prices in their local currency, while settling funds in your Stripe account's settlement currency at Stripe's guaranteed exchange rate. This setting is only shown when **Payment flow** is set to the redirect option and is enabled by default. Adaptive Pricing is applied only when all of the following conditions are met: the **Payment Action** is set to **Authorize and Capture**, the cart currency matches the store's base currency, and the base currency matches your Stripe account's settlement currency. When Adaptive Pricing is applied, the order history and the payment information section of the order in the Magento admin display the amount the customer was charged in their local currency. See [Adaptive Pricing](/payments/checkout/adaptive-pricing) for details.
- **Form layout:** Display the payment method selector in Horizontal layout (tabs), or Vertical layout (accordion). We recommend the Vertical layout for narrow sections, such as on mobile or 3-column checkout pages. You can test the two layouts in the PaymentElement's interactive [UI component](/payments/payment-element).
- **Title:** The label you want to display to the customer on the checkout page.
- **Payment Action:** Select a payment mode:
  - **Authorize and Capture**: Charge customer cards immediately after a purchase.
  - **Authorize Only**: Authorize the payment amount and place a hold on the card. You can capture the amount later [by issuing an invoice](/use-stripe-apps/adobe-commerce/payments/admin#capturing-later).
  - **Order**: Save the customer's payment method without attempting an authorization or capture. You can collect payment for an order processed in this mode by issuing an invoice from the administrative area.
- **Expired authorizations:** For card payments that you don't capture immediately, you must do so within 7 days. Any attempt to capture the amount after that returns an error. By enabling this option, the module attempts to recreate the original payment with the original card used for that order. The module saves cards automatically in `Authorize Only` mode and the customer can't delete them from their account section until you either invoice or cancel the order.
- **Automatic Invoicing:** The Authorize Only option creates a new invoice with a Pending status on checkout. After capturing the charge, the invoice status transitions to Paid. This option is useful when Payment Action is set to Authorize Only: no invoice results from completing the checkout flow. If enabled, the module automatically generates an invoice on checkout completion so you can email it to a customer before charging them.
- **Save customer payment method:** Controls whether customers can save payment methods in the Stripe vault for quicker checkout. The available options are:
  - **Disabled:** Customers can't save payment methods.
  - **Ask for customer consent:** A checkbox is displayed at checkout allowing the customer to opt-in to saving their payment method.
  - **Save without asking:** The payment method is saved automatically without requiring customer consent.
- **Card Icons:** Display card icons based on the card brands your Stripe account supports.
- **Optional Statement Descriptor:** This is an optional short description for the source of the payment, shown in the customer's bank statements. If left empty, the default descriptor configured from your Stripe Dashboard applies. This option isn't available for Multibanco, SEPA Direct Debit, or Sofort.
- **Sort Order:** If you've enabled multiple payment methods, this setting determines the order to present payment methods on the checkout page.
- **Filter payment methods:** Stripe supports [multiple configurations](/payments/payment-method-configurations) of payment methods. After you [configure the payment methods](https://dashboard.stripe.com/settings/payment_methods), they immediately become available in the dropdown menu. You can select a different configuration for each of your store views, based on business requirements. You can additionally select a different payment method configuration for virtual carts, which filters out payment methods that don't allow selling virtual items, such as Afterpay/Clearpay.


## Bank Transfers {% #bank-transfers %}

Bank transfers provide a safe way for customers to send money over bank rails. When accepting bank transfers with Stripe, you provide customers with a virtual bank account number that they can push money to from their own online bank interface or in-person bank branch. Stripe uses this virtual account number to automate reconciliation and prevent exposing your real account details to customers.

The plugin supports bank transfers for [a subset of currencies](/payments/bank-transfers#bank-transfer-methods). The supported currencies are the settlement currencies of your Stripe account, rather than the currency which the customer selects at your checkout. This means that if your Stripe account settlement currency is USD, then only customers paying in USD are able to see the bank transfer payment method on your checkout.

- **Enable**: When you enable bank transfers, they appear as a separate payment method to the PaymentElement form. This means that you can set a separate title and sort order for it.
- **Title**: The title you want to display at the checkout for this payment method.
- **Minimum amount**: When the shopping cart amount is less than the minimum amount, the payment method is hidden at the checkout. The amount is specified in the store's configured base currency.
- **Default EU Country**: Although anyone in the EU can place an order with bank transfers, virtual bank accounts can only be generated for only five out of all the EU countries. If the customer's billing address is in one of those countries, the virtual bank account is automatically be created for that country. If not, then country value which you configure here is used to generate the virtual bank account.
- **Pending Payment Order Lifetime (days):** The number of days an order remains in Pending Payment status before it is automatically canceled. This is useful for bank transfer orders where the customer hasn't yet sent the funds.
- **Sort order**: The sort order of the payment method against other enabled payment methods at the checkout.

## Stripe Invoicing {% #stripe-invoicing %}

Stripe Invoicing is available in the admin area when creating new orders. It allows you to email an invoice to the customer via Stripe. A link in the email brings the customer to a Stripe-hosted payment page. For more details on the workflow, see [Send an invoice to the customer](/use-stripe-apps/adobe-commerce/payments/admin#send-an-invoice-to-the-customer).

- **Enabled:** Enable or disable Stripe Invoicing as a payment method in the admin area.
- **Pending Payment Order Lifetime (days):** The number of days an order remains in Pending Payment status before it is automatically canceled. This allows you to control how long customers have to pay the invoice before the order is canceled.

## Express Checkout {% #express-checkout %}

Express Checkout lets customers place orders using one-click wallet buttons like [Link](/payments/link), [Apple Pay](/apple-pay), and [Google Pay](/google-pay). If supported by the customer's device, you can display multiple wallets in any order. Set your preferences in the dedicated configuration section of the Adobe Commerce admin panel.

{% image
   src="images/adobe-commerce/connector-express-checkout.png"
   width=70
   ignoreAltTextRequirement=true %}
Configuration options for Apple Pay and Google Pay
{% /image %}

- **Enabled:** Toggles the wallet button as an available payment method for chosen locations. You can turn it on even if regular payments are disabled.
- **Locations:** Specify the pages where you want the wallet buttons to appear. The available locations are:
  - **Product pages:** Wallet buttons appear on individual product pages.
  - **Minicart:** Wallet buttons appear in the minicart dropdown.
  - **Shopping cart page:** Wallet buttons appear on the shopping cart page.
  - **Checkout, top section:** Wallet buttons appear at the top of the checkout page, above the shipping and payment sections.
  - **Checkout, within payment form:** Wallet buttons appear inside the Payment Element form alongside other payment methods.
- **Seller name:** Your business name, which is displayed in the payment modal.
- **Button height:** You can modify the button height to match the **Add to Cart** and **Proceed to Checkout** buttons in your theme.
- **Overflow:** When set to `Automatic`, the wallet buttons collapse or expand, depending on the size of their container. When set to `Expanded`, all wallet buttons are visible, regardless of the container size.
- **Sort order:** By default, Stripe arranges wallets in an optimal order based on factors like device capabilities and usage patterns. You can assign a sort order to each wallet in its sub-configuration section by selecting **Use sort order field**.

If you enable Express Checkout and the wallet buttons don't appear, refer to the [troubleshooting page](/use-stripe-apps/adobe-commerce/payments/troubleshooting#wallet-button).

## Payment Method Messaging {% #payment-method-messaging %}

The [Payment Method Messaging Element](/elements/payment-method-messaging) is a UI component that informs customers about available buy-now-pay-later plans and financing options. Configure these payment plans in your [payment methods settings](https://dashboard.stripe.com/settings/payment_methods) in the Stripe Dashboard.

You can enable or disable the messaging in three locations:

- **Product pages**: Shows financing options for individual products based on the product price.
- **Minicart**: Displays options for the current cart total when customers hover over or open the minicart.
- **Shopping cart**: Shows financing plans for the full cart amount on the shopping cart page.

{% callout type="note" %}
The messaging element only displays for supported country-currency combinations. For example, the store currency must be USD for a US customer to see the messaging element.
{% /callout %}

## Subscriptions {% #subscriptions %}

Configure subscriptions powered by Stripe Billing directly from your Adobe Commerce admin. This section is visible under **Stores > Configuration > Sales > Payment Methods > Stripe > Subscriptions via Stripe Billing**.

- **Enabled:** Enable or disable subscriptions. If you don't sell subscriptions, disabling them has a small performance benefit.
- **Customers can change subscription:** Allow customers to change the quantity or options of active subscriptions from the customer account section. This will in effect upgrade or downgrade the subscription plan and price.
- **Additional info:** When enabled, subscription details are added to the cart item's info block when a subscription product is added to the cart.

### Email notifications {% #subscription-email-notifications %}

You can configure email notifications for subscription renewals and subscription changes. Both options are disabled by default.

- **Renewal email notification:** When enabled, Magento sends the standard new order email to the customer each time a recurring subscription order is created. When disabled, no email is sent for recurring subscription orders.
- **Change email notification:** When enabled, Magento sends a new order email to the customer when an active subscription is changed (for example, an upgrade or downgrade). This option is only available when **Customers can change subscription** is also enabled.

### Email templates {% #subscription-email-templates %}

When email notifications are enabled, you can optionally assign custom email templates for subscription-related emails. Custom email templates are created under **Marketing > Email Templates** in the Magento admin. If no custom template is selected, Magento falls back to the default email template for that email type.

The following template overrides are available when **Renewal email notification** is enabled:

- **Renewal email template:** The email template sent for a subscription renewal order.
- **Guest renewal email template:** The email template sent for a subscription renewal order placed by a guest.
- **Renewal invoice email template:** The email template sent for a subscription renewal invoice.
- **Guest renewal invoice email template:** The email template sent for a subscription renewal invoice placed by a guest.

The following template override is available when **Change email notification** is enabled:

- **Change email template:** The email template sent for a subscription change order.

The following template overrides are always available when subscriptions are enabled:

- **Credit memo email template:** The email template sent for a subscription credit memo.
- **Guest credit memo email template:** The email template sent for a subscription credit memo for a guest order.
- **Shipment email template:** The email template sent for a subscription shipment.
- **Guest shipment email template:** The email template sent for a subscription shipment for a guest order.

## Webhooks {% #webhooks %}

Stripe uses webhooks to notify your application when an event happens in your account. Webhooks are particularly useful for updating Magento orders when a customer's bank confirms or declines a payment, or when collecting subscription payments. These events allow the module to mark Magento orders as ready for fulfillment, record refunds against them, or add comments about payment failure reasons.

Starting from version 3 of the module, you no longer need to manually configure webhooks. The module checks and potentially configures webhooks automatically in the following cases:

- When you install or upgrade the module and trigger the `setup:upgrade` command.
- Every time you update the API keys in the Magento admin.
- Every time you change the URL of a store in the Magento admin.
- When the module detects a change in the database during one of the hourly automated checks. This prevents webhooks from being broken due to a manual change to the database, a migration from a different server, or a backup restoration.

When updating webhooks, the module creates a single webhook endpoint per Stripe account. For example, if you have five store views, four are using a Stripe account and the last one is using a different Stripe account, the module creates two webhook endpoints.

This also applies if you use different domain names for your store views. In this case, the module uses one of the store view domains and not your base URL. This is to prevent issues with base URLs often being behind a firewall for security reasons.

The module uses webhook signatures to verify that the events were sent by Stripe, not by a third party. You can disable this protection only when your Magento instance is using developer mode.

## Advanced Configuration {% #advanced-configuration %}

This section is for advanced settings of the module like {% glossary term="ic+" %}IC+ pricing{% /glossary %} settings and enabling restricted payment methods.

Some settings could cause issues for customers at the checkout page.

#### Place order first {% #place-order-first %}

When **disabled**, if 3DS is required, the payment is collected when the 3DS authentication succeeds and before the order is placed.

When **enabled**, the order is first  placed in `Pending Payment` status, and the 3DS modal opens. If 3DS succeeds, the customer is redirected to the success page. Stripe then asynchronously sends the charge.succeeded webhook event back to your website, which causes the order to switch to `Processing` or `Complete` status.

If the customer fails 3DS authentication, or if they abandon the payment process, the order automatically cancels through cron after 2-3 hours. During this time, the inventory remains reserved. This can potentially impact products which have a low stock and are in high demand.

If you need to cancel the order sooner, you can configure it with the [pending payment order lifetime](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/orders/order-scheduled-operations#set-pending-payment-order-lifetime) setting in the admin area.

The **GraphQL** option is used with custom storefronts that call GraphQL instead of the REST API. More details about a custom storefront with GraphQL can be found [here](/use-stripe-apps/adobe-commerce/payments/custom-storefront).

#### Overcapture {% #overcapture %}

Overcapture allows you to capture more than the amount authorized during order placement. The [amount you can capture](/payments/overcapture?platform=web&ui=stripe-hosted#availability-by-merchant-category) depends on the card network and your country and merchant category.

Overcapture is an {% glossary term="ic+" %}IC+{% /glossary %} feature.

When you invoice an order from the Magento admin, a new `Custom Capture Amount` input appears above **Submit Invoice**. This amount will be in the store's base currency.

Enter a custom amount to capture an alternate amount upon invoice submission, if the card network supports it. Leave the input empty otherwise.

Using overcapture to update an authorized payment can affect the accuracy of your reconciliation: custom capture amount is not reflected in the order and invoice documents or for Stripe Tax calculations.

To make sure dependent products and documents match the final payment, consider using the **Payment Action = Order** configuration setting instead.

#### Multicapture {% #multicapture %}

Partially capturing an authorization releases the remaining amount by default. To capture the remaining order amount after the initial capture, you must create a new payment, which might not succeed. You can use the Stripe multicapture feature to capture multiple installments against the same payment authorization.

Multicapture is an {% glossary term="ic+" %}IC+{% /glossary %} feature.

#### Extended authorizations {% #extended-athorizations %}

Stripe's extended authorization feature allows you to hold customer funds for up to 30 days (depending on the card network) compared to standard authorization validity periods of 7 days for online payments.

To verify if an authorization has the extended window you can place an order using test card **5555 5555 5555 4444**, go to Magento Admin and look for the *Authorization expires* entry under the *Payment Information* section of the order.

Some other considerations are that Extended authorizations require Authorize Only mode, you are responsible for compliance with all card network rules when using extended authorizations, you need to inform the customers of what is happening, and that your extended authorizations availability depends on your Merchant Category Code (MCC) for some card networks.

For more details on card network validity windows and other terms, please refer to the [extended authorizations documentation](/payments/extended-authorization).

Extended authorizations is an {% glossary term="ic+" %}IC+{% /glossary %} feature. See [availability](/payments/extended-authorization?platform=web&ui=embedded-form#availability) for more details.

#### Meses sin intereses {% #meses-sin-intereses %}

Meses sin intereses is a type of [credit card payment](/payments/mx-installments) in Mexico that allows customers to split purchases over multiple billing statements. If a customer selects to pay in installments, you receive the full amount (minus a fee), just like any other payment, and the customer's bank handles collecting the money over time.

To enable MSI, you will additionally need to activate the **Meses sin intereses** payment method in the [Stripe Dashboard](https://dashboard.stripe.com/settings/payment_methods).

#### Send payment line items to Stripe {% #payment-line-items %}

Send additional transaction metadata across supported Payment Method Types to access cost savings, facilitate payment reconciliation, and improve auth rates.

By passing payment line items, you can participate in the Level 2/Level 3/Product 3 (L2/L3) program that major card networks administer. Find out more [here](/payments/payment-line-items)

#### Send missing order emails {% #missing-order-emails %}

When a charge.succeeded webhook event arrives, an attempt will be made to record the transaction against the order, as well as switch it to Processing status. If the order is not found, an email will be sent instead to the store's configured General Email.

#### Automatic webhooks configuration {% #automatic-webhooks-configuration %}

When you change your Stripe API keys or the store's Base URL, webhooks will be automatically reconfigured. See [webhooks configuration](/use-stripe-apps/adobe-commerce/payments/configuration#webhooks) for more details.

{% see-also %}
- [Using Subscriptions](/use-stripe-apps/adobe-commerce/payments/subscriptions)
- [Using the Adobe Commerce admin panel](/use-stripe-apps/adobe-commerce/payments/admin)
- [Troubleshooting](/use-stripe-apps/adobe-commerce/payments/troubleshooting)
{% /see-also %}
