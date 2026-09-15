<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the placeMultishippingOrder mutation endpoint.
 */
class PlaceMultishippingOrderTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testPlaceMultishippingOrder(): void
    {
        $query = <<<QUERY
mutation {
  placeMultishippingOrder {
    redirect
    error
    authenticate
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        $this->assertArrayHasKey('placeMultishippingOrder', $response);
        $this->assertArrayHasKey('redirect', $response['placeMultishippingOrder']);
        $this->assertArrayHasKey('error', $response['placeMultishippingOrder']);
        $this->assertArrayHasKey('authenticate', $response['placeMultishippingOrder']);
        $this->assertNotNull($response['placeMultishippingOrder']['redirect']);
    }
}
