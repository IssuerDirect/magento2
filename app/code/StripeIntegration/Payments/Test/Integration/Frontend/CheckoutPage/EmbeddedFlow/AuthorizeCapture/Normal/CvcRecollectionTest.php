<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CvcRecollectionTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $config;
    private $paymentMethodOptions;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->config = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->paymentMethodOptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\PaymentMethodOptions::class);
    }

    /**
     * Tests that when CVC recollection is enabled for saved cards but the customer
     * is a guest (no customer session features), the server-side confirm params
     * do not include require_cvc_recollection, matching the frontend Payment Element
     * configuration which also omits it for guests.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments/cvc_code new_saved_cards
     */
    public function testGuestCheckout()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();

        // Frontend: hasCustomerSessionFeatures() determines whether the Payment Element
        // is initialized with a customer session (which enables require_cvc_recollection).
        $frontendHasCvcRecollection = $this->config->hasCustomerSessionFeatures($quote);

        // Server-side: getPaymentMethodOptions() builds the confirm params for the PaymentIntent.
        $serverOptions = $this->paymentMethodOptions->getPaymentMethodOptions($quote);
        $serverHasCvcRecollection = !empty($serverOptions['card']['require_cvc_recollection']);

        $this->assertEquals(
            $frontendHasCvcRecollection,
            $serverHasCvcRecollection,
            "Mismatch: frontend " . ($frontendHasCvcRecollection ? "has" : "does not have")
            . " require_cvc_recollection, but server-side "
            . ($serverHasCvcRecollection ? "has" : "does not have") . " it."
        );

        // For a guest with save_payment_method=0 (default), neither should have it
        $this->assertFalse($frontendHasCvcRecollection);
        $this->assertFalse($serverHasCvcRecollection);
    }

    /**
     * Tests that when CVC recollection is enabled and payment methods are always saved,
     * both the frontend and server-side include require_cvc_recollection consistently.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments/cvc_code new_saved_cards
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 2
     */
    public function testAlwaysSavePaymentMethod()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();

        $frontendHasCvcRecollection = $this->config->hasCustomerSessionFeatures($quote);

        $serverOptions = $this->paymentMethodOptions->getPaymentMethodOptions($quote);
        $serverHasCvcRecollection = !empty($serverOptions['card']['require_cvc_recollection']);

        $this->assertEquals(
            $frontendHasCvcRecollection,
            $serverHasCvcRecollection,
            "Mismatch: frontend " . ($frontendHasCvcRecollection ? "has" : "does not have")
            . " require_cvc_recollection, but server-side "
            . ($serverHasCvcRecollection ? "has" : "does not have") . " it."
        );

        // With always save, both should have it
        $this->assertTrue($frontendHasCvcRecollection);
        $this->assertTrue($serverHasCvcRecollection);
    }
}
