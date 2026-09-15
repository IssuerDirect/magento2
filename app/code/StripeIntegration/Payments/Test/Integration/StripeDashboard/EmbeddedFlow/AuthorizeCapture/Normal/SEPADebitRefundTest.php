<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SEPADebitRefundTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);

        // SEPA Debit requires mandate_data which needs a remote IP and user agent
        $request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
        $request->getServer()->set('REMOTE_ADDR', '192.168.1.1');
        $request->getServer()->set('HTTP_USER_AGENT', 'Mozilla/5.0 (Integration Test)');
        $this->objectManager->get(\Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::class)->_resetState();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testFullRefund()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("SEPADebit");

        $order = $this->quote->placeOrder();

        // Trigger payment webhooks to move to processing
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntentId);
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        // Create refund via Stripe API (simulating Stripe dashboard)
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $charge = $this->tests->stripe()->charges->retrieve($paymentIntent->latest_charge);
        $refund = $this->tests->stripe()->refunds->create(['charge' => $charge->id]);
        $this->assertEquals('pending', $refund->status);

        // Trigger charge.refunded webhook
        $this->tests->event()->trigger("charge.refunded", $charge->id);

        // The refund is pending, so the credit memo should be STATE_OPEN and order still processing
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $creditmemos = $order->getCreditmemosCollection();
        $this->assertEquals(1, $creditmemos->count());
        $creditmemo = $creditmemos->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN, $creditmemo->getState());

        // Simulate the refund succeeding via refund.updated webhook
        $this->tests->event()->trigger("refund.updated", $refund, ['status' => 'succeeded']);

        // The credit memo should now be STATE_REFUNDED and order should be closed
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals($order->getBaseGrandTotal(), $order->getBaseTotalRefunded());

        $creditmemos = $order->getCreditmemosCollection();
        $this->assertEquals(1, $creditmemos->count());
        $creditmemo = $creditmemos->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED, $creditmemo->getState());

        // Verify item-level refund totals match what a synchronous refund would produce
        foreach ($order->getAllItems() as $orderItem)
        {
            $this->assertEquals($orderItem->getQtyOrdered(), $orderItem->getQtyRefunded(), "qty_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getRowTotal(), $orderItem->getAmountRefunded(), "amount_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getBaseRowTotal(), $orderItem->getBaseAmountRefunded(), "base_amount_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getTaxAmount(), $orderItem->getTaxRefunded(), "tax_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getBaseTaxAmount(), $orderItem->getBaseTaxRefunded(), "base_tax_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getDiscountAmount(), $orderItem->getDiscountRefunded(), "discount_refunded mismatch for " . $orderItem->getSku());
            $this->assertEquals($orderItem->getBaseDiscountAmount(), $orderItem->getBaseDiscountRefunded(), "base_discount_refunded mismatch for " . $orderItem->getSku());
        }
    }
}
