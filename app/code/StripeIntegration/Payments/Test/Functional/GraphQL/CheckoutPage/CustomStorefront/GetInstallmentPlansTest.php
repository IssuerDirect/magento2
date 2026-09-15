<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the getInstallmentPlans query endpoint.
 */
class GetInstallmentPlansTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testGetInstallmentPlans(): void
    {
        $query = <<<QUERY
query {
  getInstallmentPlans
}
QUERY;
        $response = $this->graphQlQuery($query);
        $this->assertArrayHasKey('getInstallmentPlans', $response);
        $this->assertNotNull($response['getInstallmentPlans']);
    }
}
