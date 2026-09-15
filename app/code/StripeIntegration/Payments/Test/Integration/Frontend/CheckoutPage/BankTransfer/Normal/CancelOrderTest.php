<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\BankTransfer\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $cronJob;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->cronJob = $this->objectManager->get(\Magento\Sales\Model\CronJob\CleanExpiredOrders::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/minimum_amount 0
     * @magentoConfigFixture current_store sales/orders/delete_pending_after -1
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
            ->setPaymentMethod("BankTransfer");

        $order = $this->quote->placeOrder();

        // Create the payment info block for $order
        $paymentInfoBlock = $this->objectManager->create(\StripeIntegration\Payments\Block\PaymentInfo\BankTransfers::class);
        $paymentInfoBlock->setOrder($order);
        $paymentInfoBlock->setInfo($order->getPayment());

        // Test the payment info block
        $transactionId = $paymentInfoBlock->getTransactionId();
        $paymentMethod = $paymentInfoBlock->getPaymentMethod();
        $paymentIntent = $paymentInfoBlock->getPaymentIntent();
        $paymentMethodIconUrl = $paymentInfoBlock->getPaymentMethodIconUrl();
        $paymentMethodName = $paymentInfoBlock->getPaymentMethodName();
        $formattedAmountRemaining = $paymentInfoBlock->getFormattedAmountRemaining();
        $formattedAmountRefunded = $paymentInfoBlock->getFormattedAmountRefunded();
        $ibanDetails = $paymentInfoBlock->getIbanDetails();
        $reference = $paymentInfoBlock->getReference();
        $hostedInstructionsUrl = $paymentInfoBlock->getHostedInstructionsUrl();
        $customerId = $paymentInfoBlock->getCustomerId();
        $paymentId = $paymentInfoBlock->getPaymentId();
        $mode = $paymentInfoBlock->getMode();

        $this->assertNotEmpty($paymentIntent);
        $this->assertNotEmpty($paymentMethod);
        $this->assertIsArray($ibanDetails);

        $this->assertStringStartsWith("pi_", $transactionId);
        $this->assertStringStartsWith("pm_", $paymentMethod->id);
        $this->assertStringEndsWith("/bank.svg", $paymentMethodIconUrl);
        $this->assertEquals("Bank transfer", $paymentMethodName);
        $this->assertEquals("€42.50", $formattedAmountRemaining);
        $this->assertEmpty($formattedAmountRefunded);
        $this->assertNotEmpty($ibanDetails['account_holder_name']);
        $this->assertNotEmpty($ibanDetails['bic']);
        $this->assertEquals("Germany", $ibanDetails['country']);
        $this->assertStringStartsWith("DE", $ibanDetails['iban']);
        $this->assertNotEmpty($reference);
        $this->assertStringStartsWith("https://", $hostedInstructionsUrl);
        $this->assertStringStartsWith("cus_", $customerId);
        $this->assertEquals($paymentIntent->id, $paymentId);
        $this->assertEquals("test/", $mode);

        // Set days due so that the order can be canceled
        $additionalInfo = $order->getPayment()->getAdditionalInformation();
        $additionalInfo['days_due'] = 0;
        $order->getPayment()->setAdditionalInformation($additionalInfo);
        $this->tests->orderHelper->saveOrder($order);

        $paymentIntentId = $this->tests->orderHelper->getTransactionId($order);

        $this->cronJob->execute();
        $this->tests->event()->trigger('payment_intent.canceled', [
            "id" => $paymentIntentId,
            "status" => "canceled",
            "cancellation_reason" => "abandoned",
            "amount_received" => 0,
            "metadata" => [
                "Order #" => $order->getIncrementId(),
            ]
        ]);

        // Check order
        $canceledOrder = $this->tests->refreshOrder($order);
        $this->assertEquals('canceled', $canceledOrder->getState());
        $this->assertEquals('canceled', $canceledOrder->getStatus());

        $canceledStripePaymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->assertEquals("canceled", $canceledStripePaymentIntent->status);
        $this->assertEquals("abandoned", $canceledStripePaymentIntent->cancellation_reason);
    }
}
