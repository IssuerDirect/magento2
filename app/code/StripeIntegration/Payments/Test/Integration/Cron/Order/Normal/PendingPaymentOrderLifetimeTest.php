<?php

namespace StripeIntegration\Payments\Test\Integration\Cron\Order\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PendingPaymentOrderLifetimeTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;
    private $cronJob;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->cronJob = $this->objectManager->get(\Magento\Sales\Model\CronJob\CleanExpiredOrders::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action order
     * @magentoConfigFixture current_store sales/orders/delete_pending_after -1
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testPendingPaymentOrderLifetime()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $orderId = $order->getIncrementId();

        // Order checks
        $this->assertEquals(1, $order->getEmailSent(), "The order email was sent.");
        $this->assertCount(0, $order->getInvoiceCollection());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(true, $order->canEdit());
        $this->assertEquals(true, $order->canCancel());

        // Cancel the pending order
        $this->cronJob->execute();

        // Check if the order was not canceled
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
    }
}
