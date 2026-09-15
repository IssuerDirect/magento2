<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use StripeIntegration\Payments\Test\Integration\Mock\Magento\Sales\Model\Order as MockOrder;
use StripeIntegration\Payments\Test\Integration\Mock\Magento\Quote\Model\QuoteManagement as MockQuoteManagement;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CrashTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $this->objectManager->configure([
            'preferences' => [
                \Magento\Quote\Model\QuoteManagement::class => MockQuoteManagement::class,
                \Magento\Sales\Model\Order::class => MockOrder::class
            ]
        ]);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testCrashBeforeOrderSave()
    {
        $this->quote->create()
            ->setCart("SubscriptionSingle")
            ->loginOpc()
            ->setPaymentMethod("SuccessCard");

        $ordersCount = $this->tests->getOrdersCount();

        // 1st attempt - crash before order is saved
        try
        {
            MockOrder::$crashBeforeOrderSave = true;
            $order = $this->quote->placeOrder();
            $this->assertTrue(false, "The order should have crashed before being saved.");
        }
        catch (\Exception $e)
        {
            $this->assertEquals("crashBeforeOrderSave", $e->getMessage());
        }

        // No new order should exist in Magento
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount, "No order should have been created after the crash.");

        // 2nd attempt - should succeed
        MockOrder::$crashBeforeOrderSave = false;
        $order = $this->quote->placeOrder();
        $this->tests->confirmSubscription($order);
        $order = $this->tests->refreshOrder($order);

        // Check orders in Magento
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount, "Exactly one order should exist after the retry.");
        $this->assertEquals("processing", $order->getStatus(), "The order status should be processing.");

        // Check subscriptions in Stripe
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer->subscriptions->data, "The customer should have at least one subscription.");
        $this->assertCount(1, $customer->subscriptions->data, "The customer should have exactly one subscription.");
        $this->assertEquals("active", $customer->subscriptions->data[0]->status, "The subscription should be active.");
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testCrashAfterOrderSave()
    {
        $this->quote->create()
            ->setCart("SubscriptionSingle")
            ->loginOpc()
            ->setPaymentMethod("SuccessCard");

        $ordersCount = $this->tests->getOrdersCount();

        // 1st attempt - crash after order is saved
        try
        {
            MockQuoteManagement::$crashAfterOrderSave = true;
            $order = $this->quote->placeOrder();
            $this->assertTrue(false, "The order should have crashed after being saved.");
        }
        catch (\Exception $e)
        {
            $this->assertEquals("crashAfterOrderSave", $e->getMessage());
        }

        // The order should have been saved despite the crash
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount, "One order should exist after the crash (saved before crash).");

        $order = $this->tests->getLastOrder();
        $this->tests->confirmSubscription($order);
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getStatus(), "The first order status should be processing.");

        // 2nd attempt - should fail because the order was already placed
        MockQuoteManagement::$crashAfterOrderSave = false;
        try
        {
            $order2 = $this->quote->placeOrder();
            $this->assertTrue(false, "The order was placed successfully, whereas it should have failed.");
        }
        catch (\Exception $e)
        {
            $this->assertEquals("The order has already been placed and paid.", $e->getMessage());
        }

        // Still only 1 order in Magento
        $finalOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $finalOrdersCount, "No additional orders should have been created.");

        // Check subscriptions in Stripe - should be exactly 1
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer->subscriptions->data, "The customer should have at least one subscription.");
        $this->assertCount(1, $customer->subscriptions->data, "The customer should have exactly one subscription.");
        $this->assertEquals("active", $customer->subscriptions->data[0]->status, "The subscription should be active.");
    }
}
