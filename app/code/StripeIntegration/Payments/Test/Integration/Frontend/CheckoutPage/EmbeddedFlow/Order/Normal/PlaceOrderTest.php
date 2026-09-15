<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\EmbeddedFlow\Order\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $quote;
    private $tests;
    private $objectManager;
    private $paymentElementCollection;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentElementCollection = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\PaymentElement\Collection::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action order
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Payment info checks
        $payment = $order->getPayment();
        $this->assertNotEmpty($payment->getAdditionalInformation('customer_stripe_id'));
        $this->assertNotEmpty($payment->getAdditionalInformation('token'));

        // Order checks
        $this->assertTrue($order->canEdit(), "The order should be editable");
        $this->assertCount(0, $order->getInvoiceCollection());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());
        $this->assertEquals("processing", $order->getStatus());

        // Trigger payment_method.attached event
        $paymentMethodId = $order->getPayment()->getAdditionalInformation('token');
        $paymentMethod = $this->tests->stripe()->paymentMethods->retrieve($paymentMethodId);
        $this->tests->event()->trigger("payment_method.attached", $paymentMethod);

        // Trigger setup_intent.succeeded event
        $paymentElement = $this->paymentElementCollection->getByQuoteId($order->getQuoteId());
        $setupIntentId = $paymentElement->getSetupIntentId();
        $setupIntent = $this->tests->stripe()->setupIntents->retrieve($setupIntentId);
        $this->tests->event()->trigger("setup_intent.succeeded", $setupIntent);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $payment = $order->getPayment();
        $this->assertNotEmpty($payment->getAdditionalInformation('customer_stripe_id'));
        $this->assertNotEmpty($payment->getAdditionalInformation('token'));

        // Same order checks
        $this->assertTrue($order->canEdit(), "The order should be editable");
        $this->assertCount(0, $order->getInvoiceCollection());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());
        $this->assertEquals("processing", $order->getStatus());

        // Switch to the admin area
        $this->objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        $order = $this->tests->refreshOrder($order);

        // Create the payment info block for $order
        $this->assertNotEmpty($this->tests->renderPaymentInfoBlock(\StripeIntegration\Payments\Block\PaymentInfo\Element::class, $order));
    }
}
