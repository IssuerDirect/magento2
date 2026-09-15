<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\StripeInvoice\Normal;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $cronJob;

    public function setUp(): void
    {
        /** @var \Magento\TestFramework\ObjectManager $objectManager */
        $objectManager = $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $invoiceMock = $this->createMock(\StripeIntegration\Payments\Helper\Stripe\Invoice::class);
        $invoiceMock->method('getStripeInvoiceParams')->willReturn([]);
        $objectManager->addSharedInstance($invoiceMock, \StripeIntegration\Payments\Helper\Stripe\Invoice::class);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->cronJob = $this->objectManager->get(\Magento\Sales\Model\CronJob\CleanExpiredOrders::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store sales/orders/delete_pending_after -1
     */
    public function testNormalCart()
    {
        $this->quote->createAdmin()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeInvoice");

        $order = $this->quote->placeOrder();

        // Check the order
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "pending_payment",
            "status" => "pending_payment",
            "grand_total" => 53.30,
            "total_due" => $order->getGrandTotal(),
            "total_invoiced" => $order->getGrandTotal()
        ]);

        // Check the Magento invoice
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertNotEmpty($invoice);
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Check the Stripe invoice
        $invoiceId = $order->getPayment()->getAdditionalInformation("invoice_id");
        $this->assertNotEmpty($invoiceId);
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);

        $this->tests->compare($stripeInvoice, [
            "status" => "open",
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => 0,
            "customer_address" => [
                "city" => $order->getBillingAddress()->getCity(),
                "country" => $order->getBillingAddress()->getCountryId(),
                "line1" => $order->getBillingAddress()->getStreet()[0],
                "postal_code" => $order->getBillingAddress()->getPostcode(),
                "state" => $order->getBillingAddress()->getRegion()
            ],
            "customer_email" => $order->getCustomerEmail(),
            "customer_name" => $order->getBillingAddress()->getFirstname() . " " . $order->getBillingAddress()->getLastname(),
            "customer_phone" => $order->getBillingAddress()->getTelephone()
        ]);

        // Set days due so that the order can be canceled
        $additionalInfo = $order->getPayment()->getAdditionalInformation();
        $additionalInfo['days_due'] = 0;
        $order->getPayment()->setAdditionalInformation($additionalInfo);
        $this->tests->orderHelper->saveOrder($order);

        // Run cancelling cron job and trigger invoice.voided webhook handler
        $this->cronJob->execute();
        $this->tests->event()->trigger('invoice.voided', [
            "id" => $stripeInvoice->id,
            "object" => "invoice",
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ]
        ]);

        // Check order
        $canceledOrder = $this->tests->refreshOrder($order);
        $this->assertEquals('canceled', $canceledOrder->getState());
        $this->assertEquals('canceled', $canceledOrder->getStatus());

        // Check invoice
        $invoice = $canceledOrder->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(Invoice::STATE_CANCELED, $invoice->getState());

        // Check Stripe invoice
        $voidedStripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);
        $this->tests->compare($voidedStripeInvoice, [
            "status" => "void",
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => 0,
            "customer_address" => [
                "city" => $order->getBillingAddress()->getCity(),
                "country" => $order->getBillingAddress()->getCountryId(),
                "line1" => $order->getBillingAddress()->getStreet()[0],
                "postal_code" => $order->getBillingAddress()->getPostcode(),
                "state" => $order->getBillingAddress()->getRegion()
            ],
            "customer_email" => $order->getCustomerEmail(),
            "customer_name" => $order->getBillingAddress()->getFirstname() . " " . $order->getBillingAddress()->getLastname(),
            "customer_phone" => $order->getBillingAddress()->getTelephone()
        ]);
    }
}
