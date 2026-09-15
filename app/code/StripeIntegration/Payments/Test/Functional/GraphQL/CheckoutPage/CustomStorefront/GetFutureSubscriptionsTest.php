<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\CustomStorefront;

use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Tests the getFutureSubscriptions query endpoint.
 */
class GetFutureSubscriptionsTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testGetFutureSubscriptions(): void
    {
        $query = <<<QUERY
query {
  getFutureSubscriptions {
    title
    start_date_label
    frequency_label
    formatted_amount
  }
}
QUERY;
        $response = $this->graphQlQuery($query);
        $this->assertArrayHasKey('getFutureSubscriptions', $response);
        $this->assertEquals('', $response['getFutureSubscriptions']['title']);
        $this->assertArrayHasKey('start_date_label', $response['getFutureSubscriptions']);
        $this->assertArrayHasKey('frequency_label', $response['getFutureSubscriptions']);
        $this->assertArrayHasKey('formatted_amount', $response['getFutureSubscriptions']);
    }
}
