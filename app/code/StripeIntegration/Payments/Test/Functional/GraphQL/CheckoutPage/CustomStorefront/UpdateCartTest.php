<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the updateCart mutation endpoint.
 */
class UpdateCartTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testUpdateCart(): void
    {
        $query = <<<QUERY
mutation {
  updateCart {
    placeNewOrder
    reason
    error
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        $this->assertArrayHasKey('updateCart', $response);
        $this->assertArrayHasKey('placeNewOrder', $response['updateCart']);
        $this->assertArrayHasKey('reason', $response['updateCart']);
        $this->assertArrayHasKey('error', $response['updateCart']);
        $this->assertTrue($response['updateCart']['placeNewOrder']);
    }
}
