<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SEPADebitPlaceOrderTest extends \PHPUnit\Framework\TestCase
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
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("SEPADebit");

        $order = $this->quote->placeOrder();

        // Assert order is in pending payment state before webhooks
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());

        // Trigger payment webhooks
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntentId);

        // Refresh the order after webhooks
        $order = $this->tests->refreshOrder($order);

        // Assert order transitioned to processing after webhooks
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testAdminRefund()
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

        // Attempt an admin online refund - credit memo should be created in Open (pending) state
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
        $creditmemo = $creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);
        $creditmemo = $creditmemoService->refund($creditmemo);

        // Verify credit memo is in Open (pending) state and order is still processing
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN, $creditmemo->getState());
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertTrue($order->hasCreditmemos());

        // The refund is pending, so BaseTotalRefunded should not be set yet
        $this->assertEquals(0, (float)$order->getBaseTotalRefunded());

        // Verify payment status shows as "pending_refund"
        // Clear caches so the block fetches fresh PI data from Stripe (in production this is a separate HTTP request)
        $this->objectManager->get(\StripeIntegration\Payments\Helper\RequestCache::class)->clear();
        $this->objectManager->get(\StripeIntegration\Payments\Model\Stripe\PaymentIntent::class)->unsetObject();
        $paymentInfoBlock = $this->objectManager->create(\StripeIntegration\Payments\Block\PaymentInfo\Element::class);
        $paymentInfoBlock->setOrder($order);
        $paymentInfoBlock->setInfo($order->getPayment());
        $this->assertEquals("pending_refund", $paymentInfoBlock->getPaymentStatus());

        // Get the pending refund from Stripe
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $charge = $this->tests->stripe()->charges->retrieve($paymentIntent->latest_charge);
        $refunds = $this->tests->stripe()->refunds->all(['charge' => $charge->id, 'limit' => 1]);
        $refund = $refunds->data[0];
        $this->assertEquals('pending', $refund->status);

        // Simulate the refund succeeding via refund.updated webhook
        $this->tests->event()->trigger("refund.updated", $refund, ['status' => 'succeeded']);

        // Verify credit memo was created and order is closed
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("closed", $order->getState());
        $this->assertTrue($order->hasCreditmemos());

        // Verify order-level refund totals match what a synchronous refund would produce
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals($order->getBaseGrandTotal(), $order->getBaseTotalRefunded());

        // Verify credit memo is in REFUNDED state
        $creditmemo = $order->getCreditmemosCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED, $creditmemo->getState());

        // Verify item-level refund totals
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

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testAdminRefundWithStaleOrderTotals()
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

        // Attempt an admin online refund - credit memo should be created in Open (pending) state
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
        $creditmemo = $creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);
        $creditmemo = $creditmemoService->refund($creditmemo);
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN, $creditmemo->getState());

        // Simulate the old buggy behavior where BaseTotalRefunded was prematurely set
        $orderHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Order::class);
        $order = $this->tests->refreshOrder($order);
        $order->setBaseTotalRefunded($order->getBaseTotalPaid());
        $order->setTotalRefunded($order->getTotalPaid());
        $orderHelper->saveOrder($order);

        // Verify the stale totals were persisted
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($order->getBaseTotalPaid(), (float)$order->getBaseTotalRefunded(), "BaseTotalRefunded should be set to simulate the old code bug");

        // Clear the order cache so the webhook handler loads fresh order data from DB
        $this->objectManager->get(\StripeIntegration\Payments\Helper\Order::class)->clearCache();

        // Get the pending refund from Stripe
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $charge = $this->tests->stripe()->charges->retrieve($paymentIntent->latest_charge);
        $refunds = $this->tests->stripe()->refunds->all(['charge' => $charge->id, 'limit' => 1]);
        $refund = $refunds->data[0];
        $this->assertEquals('pending', $refund->status);

        // Simulate the refund succeeding via refund.updated webhook - this should not throw
        $this->tests->event()->trigger("refund.updated", $refund, ['status' => 'succeeded']);

        // Verify credit memo was finalized and order is closed
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("closed", $order->getState());
        $this->assertTrue($order->hasCreditmemos());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
    }

    /**
     * The refund.updated webhook can arrive before the afterRefund plugin has downgraded the credit memo
     * to STATE_OPEN (the "pending refund" race). The webhook must then return a retryable error instead
     * of returning 200, otherwise the credit memo is stuck in a pending state forever.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testAdminRefundWebhookRace()
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

        // Create a pending refund via the admin flow
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
        $creditmemoRepository = $this->objectManager->get(\Magento\Sales\Api\CreditmemoRepositoryInterface::class);
        $refundsHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Refunds::class);

        $creditmemo = $creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);
        $creditmemo = $creditmemoService->refund($creditmemo);
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN, $creditmemo->getState());

        // Get the pending refund from Stripe
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $charge = $this->tests->stripe()->charges->retrieve($paymentIntent->latest_charge);
        $refunds = $this->tests->stripe()->refunds->all(['charge' => $charge->id, 'limit' => 1]);
        $refund = $refunds->data[0];
        $this->assertEquals('pending', $refund->status);

        // The admin refund flow must have stored the pending-refund marker
        $this->assertTrue($refundsHelper->hasPendingRefundMarker($refund->id));

        // Simulate the race: the refund.updated webhook is processed while the credit memo is still in
        // STATE_REFUNDED, i.e. before the afterRefund plugin has downgraded it to STATE_OPEN.
        $creditmemo = $creditmemoRepository->get($creditmemo->getId());
        $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED);
        $creditmemoRepository->save($creditmemo);

        // The webhook must return a retryable error (HTTP 409) instead of returning 200 and losing the refund
        $response = $this->tests->event()->triggerWithException("refund.updated", $refund, ['status' => 'succeeded']);
        $this->assertStringContainsString("is not available yet", (string)$response);

        // The afterRefund plugin downgrades the credit memo to STATE_OPEN, and Stripe re-delivers the webhook
        $creditmemo = $creditmemoRepository->get($creditmemo->getId());
        $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN);
        $creditmemoRepository->save($creditmemo);

        $this->tests->event()->trigger("refund.updated", $refund, ['status' => 'succeeded']);

        // Verify the credit memo was finalized and the order is closed
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("closed", $order->getState());
        $creditmemo = $order->getCreditmemosCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED, $creditmemo->getState());

        // The marker must have been removed once the pending refund was finalized
        $this->assertFalse($refundsHelper->hasPendingRefundMarker($refund->id));
    }
}
