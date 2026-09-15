<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Verifies that a guest customer can place an order with a non-3DS Stripe card via GraphQL.
 */
class PlaceOrderTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testStripePlaceOrder(): void
    {
        $cartId = $this->createEmptyCart();
        $this->addSimpleProductToCart($cartId, 'simple_product');
        $this->setGuestEmailOnCart($cartId, 'guest@example.com');
        $this->setShippingAddressOnCart($cartId);
        $this->setBillingAddressOnCart($cartId);
        $this->setFlatRateShippingMethod($cartId);
        $this->setStripePaymentMethodOnCart($cartId, 'pm_card_visa', true);

        $this->placeOrder($cartId);
    }
}
