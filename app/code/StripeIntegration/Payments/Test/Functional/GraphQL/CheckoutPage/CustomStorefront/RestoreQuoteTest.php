<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the restoreQuote mutation endpoint.
 */
class RestoreQuoteTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testRestoreQuote(): void
    {
        $query = <<<QUERY
mutation {
  restoreQuote {
    error
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        $this->assertArrayHasKey('restoreQuote', $response);
        $this->assertArrayHasKey('error', $response['restoreQuote']);
        $this->assertNull($response['restoreQuote']['error']);
    }
}
