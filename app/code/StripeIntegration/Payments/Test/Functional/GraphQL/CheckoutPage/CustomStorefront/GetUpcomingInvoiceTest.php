<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the getUpcomingInvoice query endpoint.
 */
class GetUpcomingInvoiceTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testGetUpcomingInvoice(): void
    {
        $query = <<<QUERY
query {
  getUpcomingInvoice {
    new_price {
      amount
      currency
      label
    }
    credit
    error
  }
}
QUERY;
        $response = $this->graphQlQuery($query);
        $this->assertArrayHasKey('getUpcomingInvoice', $response);
        $this->assertNull($response['getUpcomingInvoice']['new_price']);
    }
}
