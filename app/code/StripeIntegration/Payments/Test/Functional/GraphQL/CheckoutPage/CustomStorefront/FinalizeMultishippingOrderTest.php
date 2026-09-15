<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the finalizeMultishippingOrder mutation endpoint.
 */
class FinalizeMultishippingOrderTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testFinalizeMultishippingOrder(): void
    {
        $query = <<<QUERY
mutation {
  finalizeMultishippingOrder(error: "Test error") {
    redirect
    error
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        $this->assertArrayHasKey('finalizeMultishippingOrder', $response);
        $this->assertArrayHasKey('redirect', $response['finalizeMultishippingOrder']);
        $this->assertArrayHasKey('error', $response['finalizeMultishippingOrder']);
        $this->assertNotNull($response['finalizeMultishippingOrder']['redirect']);
    }
}
