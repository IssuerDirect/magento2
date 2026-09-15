<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use Magento\TestFramework\Mail\Template\TransportBuilderMock;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RenewalEmailTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $transportBuilderMock;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->transportBuilderMock = $this->objectManager->get(TransportBuilderMock::class);
    }

    /**
     * Tests that when renewal_email_notification is enabled and a custom order_email_template is configured,
     * the recurring subscription order sends an email using the custom template.
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/RenewalEmailTemplate.php
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_subscriptions/renewal_email_notification 1
     * @magentoConfigFixture current_store sales_email/invoice/enabled 0
     */
    public function testRenewalEmailWithCustomTemplate()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);

        // Trigger webhook events for a recurring subscription order
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $subscription = $customer->subscriptions->data[0];
        $invoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice);

        $charge = $this->tests->getChargeFromInvoice($invoice);
        $this->tests->event()->trigger("charge.succeeded", $charge);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // A new recurring order should have been created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount, "A recurring order should have been created.");

        // Get the recurring order
        $recurringOrder = $this->tests->getLastOrder();

        // Assert the email was sent for the recurring order
        $this->assertEquals(1, $recurringOrder->getEmailSent(), "The renewal email should have been sent for the recurring order.");

        // Assert the email content uses the custom template
        $sentMessage = $this->transportBuilderMock->getSentMessage();
        $this->assertNotNull($sentMessage, "An email message should have been sent.");

        $messageContent = $sentMessage->toString();
        $this->assertStringContainsString(
            'Stripe Renewal:',
            $messageContent,
            "The email body should contain the custom renewal template text."
        );
        $this->assertStringContainsString(
            $recurringOrder->getIncrementId(),
            $messageContent,
            "The email body should contain the recurring order increment ID."
        );
    }

    /**
     * Tests that when renewal_email_notification is disabled (default),
     * no email is sent for recurring subscription orders.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_subscriptions/renewal_email_notification 0
     */
    public function testRenewalEmailDisabled()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);

        // Trigger webhook events for a recurring subscription order
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $subscription = $customer->subscriptions->data[0];
        $invoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice);

        $charge = $this->tests->getChargeFromInvoice($invoice);
        $this->tests->event()->trigger("charge.succeeded", $charge);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // A new recurring order should still have been created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount, "A recurring order should have been created.");

        // Get the recurring order
        $recurringOrder = $this->tests->getLastOrder();

        // Assert the email was NOT sent for the recurring order
        $this->assertNotEquals(1, $recurringOrder->getEmailSent(), "No email should have been sent for the recurring order when renewal email is disabled.");
    }
}
