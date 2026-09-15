<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RemoveCouponLoggedInExhaustedCouponTest extends \PHPUnit\Framework\TestCase
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
     * When a logged-in customer's per-customer coupon usage is exhausted, the
     * coupon cannot be re-applied to the restored quote. This must NOT be treated
     * as an intentional cart change — the customer should be redirected.
     *
     * Requires getUsagePerCustomer() on line 630 of CheckoutSession.php (not
     * getUsageLimit()) for the per-customer exhaustion path to fire correctly.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Customer.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/DiscountLoggedIn.php
     */
    public function testLoggedInExhaustedPerCustomerCouponNotRestored()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("single_use_per_customer_logged_in")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        $this->assertEquals("pending_payment", $order->getState());
        $this->assertFalse((bool)$order->getCustomerIsGuest());
        $discountedTotal = $order->getGrandTotal();

        // After placement the customer's usage count = 1 = usage_per_customer.
        // Simulate Magento not restoring the exhausted coupon to the quote.
        $this->quote->setCouponCode('');

        // Per-customer exhaustion — must NOT be treated as a cart change.
        $checkoutSessionUrl = $this->service->get_checkout_session_url();
        $this->assertNotNull($checkoutSessionUrl);
        $this->assertStringContainsString('checkout.stripe.com', $checkoutSessionUrl);

        // Order must remain pending — it was not cancelled.
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("pending_payment", $order->getState());

        // The new session must reflect the original discounted total.
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $session = $this->tests->stripe()->checkout->sessions->retrieve($checkoutSessionId);
        $this->assertEquals($discountedTotal, $session->amount_total / 100);
    }
}
