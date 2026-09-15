<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\EmbeddedFlow\AuthorizeOnly\AutomaticInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class FullCaptureAndRefundTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;
    private $stockRegistry;
    private $stockItemResource;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stockRegistry = $this->objectManager->get(\Magento\CatalogInventory\Model\StockRegistry::class);
        $this->stockItemResource = $this->objectManager->get(\Magento\CatalogInventory\Model\ResourceModel\Stock\Item::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 1
     * @magentoConfigFixture current_store cataloginventory/item_options/auto_return 1
     */
    public function testFullCapture()
    {
        $managedProductSku = 'managed-simple-product';
        $initialQty = 10;

        // Check stock before placing the order
        $stockItemBefore = $this->stockRegistry->getStockItemBySku($managedProductSku);
        $this->assertEquals($initialQty, $stockItemBefore->getQty(), "Stock quantity should be $initialQty before placing the order");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ManagedNormal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());

        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertTrue($invoice->canCapture());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Capture the invoice via Stripe
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id);
        $this->compare->object($paymentIntent, [
            "amount_capturable" => 5330,
            "payment_method_options" => [
                "card" => [
                    "capture_method" => "manual"
                ]
            ],
            "status" => "requires_capture"
        ]);

        // Full capture
        $paymentIntent = $this->tests->stripe()->paymentIntents->capture($paymentIntent->id);
        $charge = $this->tests->stripe()->charges->retrieve($paymentIntent->latest_charge);
        $this->assertEquals(5330, $charge->amount_captured);
        $this->tests->event()->trigger("charge.captured", $charge);
        $this->tests->event()->trigger("payment_intent.succeeded", $paymentIntent);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->tests->compare($order->getData(), [
            "total_paid" => 53.30,
            "total_refunded" => "unset",
            "total_canceled" => "unset",
            "total_due" => "0.0000",
            "state" => "processing",
            "status" => "processing"
        ]);

        $transactions = $this->helper->getOrderTransactions($order);
        $captures = $authorizations = $refunds = 0;
        foreach ($transactions as $t)
        {
            switch ($t->getTxnType())
            {
                case "capture":
                    $captures++;
                    break;
                case "authorization":
                    $authorizations++;
                    break;
                case "refund":
                    $refunds++;
                    break;
            }
        }

        $this->assertEquals(1, $captures);
        $this->assertEquals(1, $authorizations);
        $this->assertEquals(0, $refunds);

        // Check the invoice
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Ship the order - this is when stock is deducted for physical products
        $this->tests->shipOrder($order->getId());

        // Stock check after shipment - use resource model to load fresh from DB
        $stockItemAfterShipment = $this->objectManager->create(\Magento\CatalogInventory\Model\Stock\Item::class);
        $this->stockItemResource->load($stockItemAfterShipment, $stockItemBefore->getItemId());
        $this->assertEquals($initialQty - 2, $stockItemAfterShipment->getQty(), "Stock quantity for $managedProductSku should be reduced by 2 after shipment");

        // Fully refund the charge via Stripe API
        $refund = $this->tests->stripe()->refunds->create(['charge' => $charge]);
        $this->tests->event()->trigger("charge.refunded", $charge->id);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->tests->orderHelper->loadOrderByIncrementId($order->getIncrementId());
        $this->tests->compare($order->getData(), [
            "grand_total" => "53.3000",
            "total_refunded" => "53.3000",
            "state" => "closed",
            "status" => "closed"
        ]);

        // Check that items were returned to stock after the credit memo
        $stockItemAfterRefund = $this->objectManager->create(\Magento\CatalogInventory\Model\Stock\Item::class);
        $this->stockItemResource->load($stockItemAfterRefund, $stockItemBefore->getItemId());
        $this->assertEquals($initialQty, $stockItemAfterRefund->getQty(), "Stock quantity for $managedProductSku should be restored to $initialQty after credit memo");
    }
}
