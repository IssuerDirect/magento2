<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Exercises the returning-customer flow: a logged-in customer adds a payment method to
 * their Stripe customer via `addStripePaymentMethod`, then places an order that reuses
 * the saved payment method by passing its `pm_...` id to `setPaymentMethodOnCart`.
 */
class PlaceOrderWithSavedPaymentMethodTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoApiDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/saved_payment_method_customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture default_store payment/stripe_payments/save_payment_method 2
     */
    public function testPlaceOrderUsingSavedPaymentMethod(): void
    {
        $headers = $this->getCustomerHeaders();

        $savedPaymentMethodId = $this->addSavedPaymentMethod($headers);

        $cartId = $this->createEmptyCart($headers);
        $this->addSimpleProductToCart($cartId, 'simple_product', 1, $headers);
        $this->setShippingAddressOnCart($cartId, $headers);
        $this->setBillingAddressOnCart($cartId, $headers);
        $this->setFlatRateShippingMethod($cartId, $headers);
        $this->setStripePaymentMethodOnCart($cartId, $savedPaymentMethodId, false, $headers);

        $this->placeOrder($cartId, $headers);
    }

    private function addSavedPaymentMethod(array $headers): string
    {
        $query = <<<QUERY
mutation {
  addStripePaymentMethod(input: { payment_method: "pm_card_visa" }) {
    id
    type
    brand
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $headers);

        self::assertArrayHasKey('addStripePaymentMethod', $response);
        self::assertNotEmpty($response['addStripePaymentMethod']['id']);
        self::assertSame('card', $response['addStripePaymentMethod']['type']);

        return $response['addStripePaymentMethod']['id'];
    }
}
