<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the getCheckoutPaymentMethods query endpoint.
 */
class GetCheckoutPaymentMethodsTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testGetCheckoutPaymentMethods(): void
    {
        $cartId = $this->createEmptyCart();
        $this->addSimpleProductToCart($cartId, 'simple_product');
        $this->setGuestEmailOnCart($cartId, 'guest@example.com');
        $this->setShippingAddressOnCart($cartId);
        $this->setBillingAddressOnCart($cartId);
        $this->setFlatRateShippingMethod($cartId);

        $query = <<<QUERY
query {
  getCheckoutPaymentMethods(input: {
    billingAddress: "{\"firstname\":\"John\",\"lastname\":\"Doe\",\"street\":[\"64 Strawberry Dr\",\"Beverly Hills\"],\"city\":\"Los Angeles\",\"region\":\"CA\",\"postcode\":\"90210\",\"country_id\":\"US\",\"email\":\"guest@example.com\"}"
    shippingAddress: "{\"firstname\":\"John\",\"lastname\":\"Doe\",\"street\":[\"3320 N Crescent Dr\",\"Beverly Hills\"],\"city\":\"Los Angeles\",\"region\":\"CA\",\"postcode\":\"90210\",\"country_id\":\"US\"}"
  }) {
    methods
  }
}
QUERY;
        try
        {
            $response = $this->graphQlQuery($query);
            $this->assertArrayHasKey('getCheckoutPaymentMethods', $response);
            $this->assertArrayHasKey('methods', $response['getCheckoutPaymentMethods']);
        }
        catch (\Magento\TestFramework\TestCase\GraphQl\ResponseContainsErrorsException $e)
        {
            $this->assertStringContainsString('getCheckoutPaymentMethods', $e->getMessage());
        }
    }
}
