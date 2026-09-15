<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\EmbeddedFlow\AuthorizeOnly\ManualInvoicing\Normal;

use StripeIntegration\Payments\Test\Integration\Mock\StripeIntegration\Payments\Model\Config as ConfigMock;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiredAuthorizationTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;
    private $config;
    private $orderHelper;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $this->objectManager->configure([
            'preferences' => [
                \StripeIntegration\Payments\Model\Config::class => ConfigMock::class,
            ]
        ]);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->config = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->orderHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Order::class);
    }

    /**
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysIsolated.php
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testExpiredAuthorizationDoesNotCancelOrder()
    {
        $this->config->manualAuthenticationPaymentMethods = [];

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("AuthenticationRequiredCard");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Get the payment intent ID from the order
        $paymentIntentId = $this->orderHelper->getTransactionId($order);
        $this->assertNotEmpty($paymentIntentId, "The order should have a payment intent ID");

        // Record the order state before the webhook event
        $stateBeforeEvent = $order->getState();

        // Simulate an expired payment intent webhook event.
        // When Stripe detects an authorization has expired, it sends a
        // payment_intent.canceled event with cancellation_reason = "automatic"
        // and capture_method = "manual". The order should NOT be canceled so it
        // can be re-authorized via invoicing.
        $this->tests->event()->trigger("payment_intent.canceled", [
            "id" => $paymentIntentId,
            "object" => "payment_intent",
            "status" => "canceled",
            "cancellation_reason" => "automatic",
            "capture_method" => "manual",
            "amount_received" => 0,
            "amount" => 4250,
            "currency" => "usd",
            "metadata" => [
                "Order #" => $orderIncrementId
            ]
        ]);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // The order should NOT be canceled; it should remain open so it
        // can be invoiced to re-authorize the payment
        $this->assertNotEquals("canceled", $order->getState(),
            "The order should not be canceled when the authorization has expired");

        // The order state should remain unchanged from before the event
        $this->assertEquals($stateBeforeEvent, $order->getState(),
            "The order state should remain unchanged after an expired authorization event");

        // Verify that an order comment was added about the expired authorization
        $histories = $order->getStatusHistories();
        $found = false;
        foreach ($histories as $history)
        {
            if (strpos($history->getComment(), "payment authorization has expired") !== false)
            {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "The order should have a comment about the payment authorization having expired");
    }

    /**
     * With newer Stripe API versions, a PaymentIntent created via the Embedded flow reports a
     * top-level capture_method of "automatic_async" even though manual capture is set per payment
     * method in payment_method_options. An expired authorization for such an intent must still NOT
     * cancel the order so it can be re-authorized via invoicing.
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysIsolated.php
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testExpiredAuthorizationWithAutomaticAsyncCaptureMethodDoesNotCancelOrder()
    {
        $this->config->manualAuthenticationPaymentMethods = [];

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("AuthenticationRequiredCard");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Get the payment intent ID from the order
        $paymentIntentId = $this->orderHelper->getTransactionId($order);
        $this->assertNotEmpty($paymentIntentId, "The order should have a payment intent ID");

        // Record the order state before the webhook event
        $stateBeforeEvent = $order->getState();

        // Simulate an expired payment intent webhook event as it is delivered by newer Stripe API
        // versions for the Embedded flow in authorize-only mode: top-level capture_method is
        // "automatic_async" and manual capture is set per payment method.
        $this->tests->event()->trigger("payment_intent.canceled", [
            "id" => $paymentIntentId,
            "object" => "payment_intent",
            "status" => "canceled",
            "cancellation_reason" => "expired",
            "capture_method" => "automatic_async",
            "payment_method_options" => [
                "card" => [ "capture_method" => "manual" ],
                "klarna" => [ "capture_method" => "manual" ],
                "link" => [ "capture_method" => "manual" ]
            ],
            "amount_received" => 0,
            "amount" => 4250,
            "currency" => "usd",
            "metadata" => [
                "Order #" => $orderIncrementId
            ]
        ]);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // The order should NOT be canceled; it should remain open so it
        // can be invoiced to re-authorize the payment
        $this->assertNotEquals("canceled", $order->getState(),
            "The order should not be canceled when the authorization has expired");

        // The order state should remain unchanged from before the event
        $this->assertEquals($stateBeforeEvent, $order->getState(),
            "The order state should remain unchanged after an expired authorization event");

        // Verify that an order comment was added about the expired authorization
        $histories = $order->getStatusHistories();
        $found = false;
        foreach ($histories as $history)
        {
            if (strpos($history->getComment(), "payment authorization has expired") !== false)
            {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "The order should have a comment about the payment authorization having expired");
    }
}
