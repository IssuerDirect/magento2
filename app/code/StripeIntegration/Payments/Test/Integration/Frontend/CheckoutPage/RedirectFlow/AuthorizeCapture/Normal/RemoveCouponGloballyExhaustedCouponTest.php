<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RemoveCouponGloballyExhaustedCouponTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $service;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * When a single-use (globally exhausted) coupon cannot be re-applied to the
     * restored quote, the total mismatch must NOT be treated as a cart change.
     * The customer should be redirected to a new Stripe Checkout Session that
     * still reflects the original discounted order amount.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/DiscountDifferentCouponUses.php
     */
    public function testExhaustedGlobalCouponNotRestored()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("single_use_global")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        $this->assertEquals("pending_payment", $order->getState());
        $discountedTotal = $order->getGrandTotal();

        // After order placement, times_used = 1 = usage_limit, so the coupon
        // is globally exhausted. Simulate Magento failing to re-apply it to
        // the restored quote (same observable state as an intentional removal).
        $this->quote->setCouponCode('');

        // Because the coupon is exhausted, this must NOT be treated as a cart
        // change. A new Stripe Checkout Session should be created and its URL
        // returned so the customer is redirected.
        $checkoutSessionUrl = $this->service->get_checkout_session_url();
        $this->assertNotNull($checkoutSessionUrl);
        $this->assertStringContainsString('checkout.stripe.com', $checkoutSessionUrl);

        // The original order must still be pending — it was not cancelled.
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("pending_payment", $order->getState());

        // The new Stripe session must reflect the discounted order total.
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $session = $this->tests->stripe()->checkout->sessions->retrieve($checkoutSessionId);
        $this->assertEquals($discountedTotal, $session->amount_total / 100);
    }
}
