<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\BankTransfer\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $refundSucceeded = false;
    private $mockCurrency = 'usd';
    private $mockAmount = 0;
    private $mockRefundId = null;

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

        // Register the mock RefundFactory early so all classes that depend on it
        // (Refunds helper, PaymentMethod, etc.) get the mock when they are first created.
        $this->setupRefundMock($objectManager);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    private function setupRefundMock($objectManager)
    {
        $refundModelMock = $this->getMockBuilder(\StripeIntegration\Payments\Model\Stripe\Refund::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fromRefundId', 'fromObject', 'isSucceeded', 'isPending', 'getCurrency', 'getAmount', 'getCreatedTimestamp', 'createObject', 'getId'])
            ->getMock();
        $refundModelMock->method('fromRefundId')->willReturn($refundModelMock);
        $refundModelMock->method('fromObject')->willReturn($refundModelMock);
        $refundModelMock->method('isPending')->willReturnCallback(function () { return !$this->refundSucceeded; });
        $refundModelMock->method('isSucceeded')->willReturnCallback(function () { return $this->refundSucceeded; });
        $refundModelMock->method('getCreatedTimestamp')->willReturn(time());
        $refundModelMock->method('getCurrency')->willReturnCallback(function () { return $this->mockCurrency; });
        $refundModelMock->method('getAmount')->willReturnCallback(function () { return $this->mockAmount; });
        $refundModelMock->method('getId')->willReturnCallback(function () { return $this->mockRefundId; });
        $refundModelMock->method('createObject')->willReturnCallback(function ($data) use ($refundModelMock, $objectManager) {
            // Delegate to the real Stripe API for creating the refund
            $config = $objectManager->get(\StripeIntegration\Payments\Model\Config::class);
            $createdRefund = $config->getStripeClient()->refunds->create($data);
            $this->mockRefundId = $createdRefund->id;
            return $refundModelMock;
        });

        $refundModelFactory = $this->getMockBuilder(\StripeIntegration\Payments\Model\Stripe\RefundFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $refundModelFactory->method('create')->willReturn($refundModelMock);
        $objectManager->addSharedInstance($refundModelFactory, \StripeIntegration\Payments\Model\Stripe\RefundFactory::class);
    }

    /**
     * Tests the async refund flow for payment methods where the refund status is initially "pending"
     * (e.g. bank debit refunds). In production, Stripe sends charge.refunded with status=pending,
     * then refund.updated when the refund succeeds or fails. We simulate this with mocks since
     * Stripe test mode always returns status=succeeded for refunds.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/minimum_amount 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     * @magentoConfigFixture current_store tax/calculation/algorithm ROW_BASE_CALCULATION
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testRefund()
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

        // Pay the Stripe invoice
        $invoiceId = $order->getPayment()->getAdditionalInformation('invoice_id');
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);
        $paymentMethod = $this->tests->stripe()->paymentMethods->attach("pm_card_visa", [
            'customer' => $stripeInvoice->customer
        ]);
        $stripeInvoice = $this->tests->stripe()->invoices->pay($invoiceId, [
            'payment_method' => $paymentMethod->id
        ]);

        // Trigger payment webhooks
        $charge = $this->tests->getChargeFromInvoice($stripeInvoice);
        $this->tests->event()->trigger("charge.succeeded", $charge);
        $this->tests->event()->trigger("invoice.paid", $stripeInvoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $stripeInvoice);
        $order = $this->tests->refreshOrder($order);

        // Set the expected refund currency and amount for the mock.
        $charge = $this->tests->stripe()->charges->retrieve($charge->id, []);
        $this->mockCurrency = $charge->currency;
        $this->mockAmount = 3910;

        // Refund the order invoice online - the pending mock keeps the order in processing
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->tests->refundOnline($invoice, ['virtual-product' => 2, 'simple-product' => 2], $baseShipping = 10);

        $charge = $this->tests->stripe()->charges->retrieve($charge->id, []);
        $this->assertEquals(3910, $charge->amount_refunded);

        $this->tests->event()->trigger("charge.refunded", $charge);

        // The refund is pending, so the order should still be processing
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal()
        ]);

        // Simulate the refund succeeding via refund.updated webhook
        $refunds = $this->tests->stripe()->refunds->all(['charge' => $charge->id, 'limit' => 1]);
        $refund = $refunds->data[0];
        $this->refundSucceeded = true;
        $this->tests->event()->trigger("refund.updated", $refund, ['status' => 'succeeded']);

        // Check the order is now closed
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "closed",
            "status" => "closed",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => 39.10
        ]);
    }
}
