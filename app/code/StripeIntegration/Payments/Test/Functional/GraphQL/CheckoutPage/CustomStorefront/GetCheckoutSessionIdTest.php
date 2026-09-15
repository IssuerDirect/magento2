<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the getCheckoutSessionId query endpoint.
 */
class GetCheckoutSessionIdTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testGetCheckoutSessionId(): void
    {
        $query = <<<QUERY
query {
  getCheckoutSessionId
}
QUERY;
        $response = $this->graphQlQuery($query);
        $this->assertArrayHasKey('getCheckoutSessionId', $response);
        $this->assertNull($response['getCheckoutSessionId']);
    }
}
