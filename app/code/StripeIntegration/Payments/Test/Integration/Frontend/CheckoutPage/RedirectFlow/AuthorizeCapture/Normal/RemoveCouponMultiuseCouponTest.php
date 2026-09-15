<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RemoveCouponMultiuseCouponTest extends \PHPUnit\Framework\TestCase
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
     * When a customer returns from Stripe and removes a multi-use coupon,
     * quoteIsDifferentFromOrder() must detect the total mismatch as intentional
     * and return null so a new order can be placed at the full price.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/DiscountDifferentCouponUses.php
     */
    public function testRemoveMultiUseCoupon()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("multiple_use_global")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        $this->assertEquals("pending_payment", $order->getState());
        $this->assertNotEmpty($order->getCouponCode());

        // The customer returns to the checkout page and removes the coupon.
        // The quote helper's save() calls collectTotals(), so the quote grand
        // total is recalculated at the full price before the next API call.
        $this->quote->setCouponCode('');

        // The coupon is multi-use (no usage_limit), so the total mismatch is
        // treated as an intentional cart change — no redirect should happen.
        $checkoutSessionUrl = $this->service->get_checkout_session_url();
        $this->assertNull($checkoutSessionUrl);

        // The pending order must be cancelled since the cart changed.
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getStatus());
    }
}
