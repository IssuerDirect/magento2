<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RemoveCouponLoggedInMultiuseCouponTest extends \PHPUnit\Framework\TestCase
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
     * Same as testRemoveMultiUseCoupon but for a logged-in customer.
     * The per-customer usage check must not suppress the "intentional removal"
     * detection when the coupon has no per-customer limit.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Customer.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/DiscountLoggedIn.php
     */
    public function testLoggedInRemoveMultiUseCoupon()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_discount_logged_in")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        $this->assertEquals("pending_payment", $order->getState());
        $this->assertNotEmpty($order->getCouponCode());
        $this->assertFalse((bool)$order->getCustomerIsGuest());

        $this->quote->setCouponCode('');

        $checkoutSessionUrl = $this->service->get_checkout_session_url();
        $this->assertNull($checkoutSessionUrl);

        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getStatus());
    }
}
