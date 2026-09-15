---
title: Manage fraud and disputes
subtitle: Protect your business with Stripe Radar fraud prevention and handle disputes effectively.
route: /use-stripe-apps/adobe-commerce/payments/fraud-disputes
stripe_products:
  - radar
---

The Stripe Connector for Adobe Commerce provides fraud protection and dispute management capabilities through Stripe Radar and automated dispute handling. This integration helps protect your business from fraudulent transactions and manage payment disputes.

## Fraud prevention with Stripe Radar {% #radar %}

{% glossary term="radar" %}Radar{% /glossary %} provides real-time fraud protection and requires no additional development time. Fraud professionals can add [Radar for Fraud Teams](https://stripe.com/radar/fraud-teams) to customize protection and get deeper insights.

If Radar detects a high-risk payment, it might place it under review with an **Elevated** risk status.

{% image
   src="images/adobe-commerce/radar-result.png"
   width=70
   alt="Radar detects elevated risk" %}
{% /image %}

If you have Radar for Fraud protection, you can create custom [Radar rules](https://dashboard.stripe.com/test/settings/radar/rules) to automatically decline charges with elevated risk or mark them for manual review. Adobe Commerce automatically places all payments marked for manual review in **Manual Review Required** status.

To test a fraudulent payment, switch the module to test mode and place an order using the card number **4000 0000 0000 9235**.

## Manual review workflows {% #manual-reviews %}

Radar for Fraud users can flag payments for human [review](/radar/reviews).

When a payment meets the conditions you define in Radar, Stripe marks it for review and sends a webhook event to Adobe Commerce, setting the payment to *manual review* status. You must then review the payment details and either **Approve** or **Refund** the payment.

### Approve a manual review

If you determine the payment isn't fraudulent, click **Approve** on the order page to proceed with your order fulfillment process.

After you approve an order in Adobe Commerce admin, it transitions to the last status before it was placed under manual review. Both the payment in the Stripe Dashboard and the order in Adobe Commerce admin reflect the name of the admin user who approved the review.

### Refund a manual review

If you determine the payment is fraudulent, click **Refund** on the order page to return the payment and close the order.

Refunding an order creates an online credit memo in Adobe Commerce and updates the order with the details of the admin user who refunded the order.

Refunding an order with an open (unsent) invoice or no invoices cancels the order without issuing a refund, as no payment completed. You must refund orders with one or more paid invoices through the Stripe Dashboard.

### Review in the Stripe Dashboard

You can also approve and refund review items in the Stripe Dashboard. This triggers the same updates to the order and also allows you to select a refund reason to include in the order comment.

## Manage disputes {% #disputes %}

A [dispute](/disputes), also known as a chargeback, occurs when a cardholder questions your payment with their card issuer.

You must respond to disputes in the [Stripe Dashboard](/disputes/responding). Actions you take in the Stripe Dashboard appear in Adobe Commerce.

### How dispute handling works {% #how-disputes-work %}

Adobe Commerce updates orders involving disputes when we receive webhook events for the following actions.

{% table %}
- Event
- Resulting actions
---
- Dispute created
- - Updates order to **Disputed** state.
   - Stops invoicing and shipping for the order.
   - Updates order in Adobe Commerce with comments including the dispute reason and any debits to your Stripe balance.
---
- Dispute resolved for merchant
- - Closes the dispute in the Stripe dashboard.
   - Updates the order in Adobe Commerce with comments including the dispute status and any reinstated funds to your Stripe balance.
   - Updates the order to its pre-dispute state for any further processing.
---
- Dispute resolved against merchant
- - Uses previously withdrawn funds to refund the customer and cover dispute fees.
   - Updates the order in Adobe Commerce with comments including the dispute status and any funds movement.
   - If the order is invoiced, creates a credit memo against the invoice, and closes the order. If multiple invoices exist, the merchant must manually refund the invoices offline.
 {% /table %}

{% see-also %}
- [Stripe Radar documentation](/radar)
- [Dispute handling in Stripe](/disputes)
- [Configure Radar rules](https://dashboard.stripe.com/settings/radar/rules)
{% /see-also %}
