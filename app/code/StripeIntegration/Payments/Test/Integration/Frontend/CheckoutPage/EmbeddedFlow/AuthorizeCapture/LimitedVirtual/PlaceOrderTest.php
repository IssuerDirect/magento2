<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\LimitedVirtual;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;
    private $stockRegistry;
    private $stockItemResource;
    private $getProductSalableQty;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->stockRegistry = $this->objectManager->get(\Magento\CatalogInventory\Model\StockRegistry::class);
        $this->stockItemResource = $this->objectManager->get(\Magento\CatalogInventory\Model\ResourceModel\Stock\Item::class);

        // GetProductSalableQtyInterface is only available in Magento 2.3+ with MSI
        try {
            $this->getProductSalableQty = $this->objectManager->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class);
        } catch (\Exception $e) {
            $this->getProductSalableQty = null;
        }
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testLimitedVirtualCart()
    {
        $sku = 'limited-virtual-product';

        // Check stock before placing the order
        $stockItemBefore = $this->stockRegistry->getStockItemBySku($sku);
        $itemQtyBefore = $stockItemBefore->getQty();
        $saleableQtyBefore = $this->getSaleableQty($sku);

        $this->assertEquals(10, $itemQtyBefore, "Item quantity should be 10 before placing the order");
        $this->assertEquals(10, $saleableQtyBefore, "Saleable quantity should be 10 before placing the order");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("LimitedVirtual")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Create the payment info block for $order
        $paymentInfoBlock = $this->objectManager->create(\StripeIntegration\Payments\Block\PaymentInfo\Element::class);
        $paymentInfoBlock->setOrder($order);
        $paymentInfoBlock->setInfo($order->getPayment());

        // Test the payment info block
        $paymentMethod = $paymentInfoBlock->getPaymentMethod();
        $formattedAmount = $paymentInfoBlock->getFormattedAmount();
        $paymentStatus = $paymentInfoBlock->getPaymentStatus();
        $paymentIntent = $paymentInfoBlock->getPaymentIntent();
        $mode = $paymentInfoBlock->getMode();
        $riskElementClass = $paymentInfoBlock->getRiskElementClass();

        $this->assertNotEmpty($paymentIntent);
        $this->assertNotEmpty($paymentMethod);

        $this->assertStringStartsWith("pm_", $paymentMethod->id);
        $this->assertEquals("$10.83", $formattedAmount);
        $this->assertEquals("succeeded", $paymentStatus);
        $this->assertStringStartsWith("pi_", $paymentIntent->id);
        $this->assertEquals("test/", $mode);
        $this->assertEquals("normal", $riskElementClass);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->count());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(1, count($invoice->getAllItems()));
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        $transactions = $this->helper->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));

        // As of v3.3.2, guest checkouts no longer have a Stripe customer object created, unless absolutely needed (5 cases)
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->assertEmpty($paymentIntent->customer);

        // After processing webhook events, the order should remain unchanged
        $this->tests->event()->triggerPaymentIntentEvents($order->getPayment()->getLastTransId());
        $order = $this->tests->refreshOrder($order);

        // Check stock after placing the order - use resource model to load fresh from DB
        $stockItemAfter = $this->objectManager->create(\Magento\CatalogInventory\Model\Stock\Item::class);
        $this->stockItemResource->load($stockItemAfter, $stockItemBefore->getItemId());
        $itemQtyAfter = $stockItemAfter->getQty();
        $saleableQtyAfter = $this->getSaleableQty($sku, $stockItemBefore->getItemId());

        $this->assertEquals(9, $itemQtyAfter, "Item quantity should be 9 after placing the order");
        $this->assertEquals(9, $saleableQtyAfter, "Saleable quantity should be 9 after placing the order");
    }

    /**
     * Get the saleable quantity for a product SKU
     *
     * @param string $sku
     * @param int|null $stockItemId - Pass the stock item ID to reload fresh from DB
     */
    private function getSaleableQty(string $sku, ?int $stockItemId = null): float
    {
        if ($this->getProductSalableQty) {
            // Magento 2.4.9+ caches MSI reservations and stock data in
            // the frontend area, so we clear those caches to get fresh
            // post-order values
            $reservationsCacheStorage = $this->objectManager->get(\Magento\InventoryReservations\Model\GetReservationsQuantity\CacheStorage::class);
            $reservationsCacheStorage->delete($sku, 1);
            $stockItemDataCache = $this->objectManager->get(\Magento\InventoryIndexer\Model\GetStockItemData\CacheStorage::class);
            $stockItemDataCache->delete($sku, 1);

            return $this->getProductSalableQty->execute($sku, 1);
        }

        // Fall back to stock item qty for Magento versions without MSI
        if ($stockItemId) {
            // Use resource model to load fresh from DB
            $stockItem = $this->objectManager->create(\Magento\CatalogInventory\Model\Stock\Item::class);
            $this->stockItemResource->load($stockItem, $stockItemId);
        } else {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        }
        return $stockItem->getQty();
    }
}
