<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\BankTransfer\Normal;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelAdminOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $cronJob;

    public function setUp(): void
    {
        /** @var \Magento\TestFramework\ObjectManager $objectManager */
        $objectManager = $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $bankTransfersHelperMock = $this->getMockBuilder(\StripeIntegration\Payments\Helper\BankTransfers::class)
            ->setConstructorArgs([
                $objectManager->get(\StripeIntegration\Payments\Helper\Quote::class),
                $objectManager->get(\StripeIntegration\Payments\Helper\Config::class),
                $objectManager->get(\StripeIntegration\Payments\Helper\Store::class)
            ])
            ->onlyMethods(['getStripeInvoiceNumber'])
            ->getMock();

        $bankTransfersHelperMock->method('getStripeInvoiceNumber')
            ->willReturn(null);

        $objectManager->addSharedInstance(
            $bankTransfersHelperMock,
            \StripeIntegration\Payments\Helper\BankTransfers::class
        );

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->cronJob = $this->objectManager->get(\Magento\Sales\Model\CronJob\CleanExpiredOrders::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/minimum_amount 0
     * @magentoConfigFixture current_store sales/orders/delete_pending_after -1
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     * @magentoConfigFixture current_store tax/calculation/algorithm ROW_BASE_CALCULATION
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testNormalCart()
    {
        $this->quote->createAdmin()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setCouponCode("10_percent")
            ->setPaymentMethod("BankTransferAdmin");

        $order = $this->quote->placeOrder();

        // Check the order
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "pending_payment",
            "status" => "pending_payment",
            "grand_total" => 39.10,
            "total_due" => 0,
            "total_invoiced" => $order->getGrandTotal()
        ]);

        // Check the Magento invoice
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertNotEmpty($invoice);
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Check the Stripe invoice
        $invoiceId = $order->getPayment()->getAdditionalInformation('invoice_id');
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
