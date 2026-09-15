<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\AutomaticInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ElevatedRiskRejectedTest extends \PHPUnit\Framework\TestCase
{
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testUnholdOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("ElevatedRiskCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("stripe_manual_review", $order->getState());
        $this->assertEquals("stripe_manual_review", $order->getStatus());
        $this->assertEquals(0, $invoicesCollection->count());

        // because the payment is only authorized, your only option is to cancel, as the payment was not captured
        $this->tests->event()->trigger("review.closed", [
            "id" => "prv_1JDnB8HLyfDWKHBq36KwlmhZ",
            "object" => "review",
            "billing_zip" => null,
            "charge" => null,
            "closed_reason" => "canceled",
            "created" => 1626427074,
            "ip_address" => null,
            "ip_address_location" => null,
            "livemode" => false,
            "open" => false,
            "opened_reason" => "rule",
            "payment_intent" => $paymentIntent->id,
            "reason" => "canceled",
            "session" => null
        ]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getState());
        $this->assertEquals("canceled", $order->getStatus());
    }
}
