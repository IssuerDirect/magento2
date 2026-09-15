<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RedirectNoInvoiceTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testNoOpenInvoiceOnPlace()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("RedirectBasedMethod");

        $order = $this->quote->placeOrder();

        // Reload from DB so we are not seeing the cached in-memory invoice
        // collection populated by InvoiceService::prepareInvoice().
        $orderRepository = $this->objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $order = $orderRepository->get($order->getId());

        // Order should be in pending_payment
        $this->assertEquals(
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            $order->getState(),
            "Order should be in pending_payment state"
        );

        // No invoice should have been persisted
        $invoiceCollection = $this->objectManager->create(
            \Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class
        );
        $invoiceCollection->addFieldToFilter('order_id', $order->getId());
        $persistedInvoices = $invoiceCollection->getItems();
        $this->assertCount(
            0,
            $persistedInvoices,
            "No invoice should be persisted in DB. Found: " . count($persistedInvoices)
        );

        // Order-level invoiced totals must be zero
        $this->assertEquals(0.0, (float)$order->getTotalInvoiced(), "total_invoiced must be 0");
        $this->assertEquals(0.0, (float)$order->getBaseTotalInvoiced(), "base_total_invoiced must be 0");
        $this->assertEquals(0.0, (float)$order->getSubtotalInvoiced(), "subtotal_invoiced must be 0");

        // Per-item invoiced totals must be zero
        foreach ($order->getAllVisibleItems() as $item)
        {
            $this->assertEquals(0.0, (float)$item->getQtyInvoiced(), "qty_invoiced must be 0 for item " . $item->getSku());
            $this->assertEquals(0.0, (float)$item->getRowInvoiced(), "row_invoiced must be 0 for item " . $item->getSku());
        }

        // Confirm the next_action.type that triggered our path
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $pi = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $this->assertEquals('requires_action', $pi->status);
        $this->assertNotEmpty($pi->next_action);
    }
}
