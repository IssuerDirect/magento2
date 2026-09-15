<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\Initialization;

use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Verifies that the `getStripeConfiguration` query returns the parameters a custom
 * storefront needs to initialize Stripe.js and the Payment Element (publishable key,
 * locale, appInfo, betas and serialized elementsOptions).
 *
 * This is the first call every custom storefront makes, so if it regresses the whole
 * custom-storefront integration breaks.
 */
class GetStripeConfigurationTest extends GraphQlAbstract
{
    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testReturnsInitializationParameters(): void
    {
        $query = <<<QUERY
query {
  getStripeConfiguration {
    apiKey
    locale
    appInfo {
      name
      partner_id
      url
      version
    }
    options {
      betas
    }
    elementsOptions
  }
}
QUERY;
        $response = $this->graphQlQuery($query);

        self::assertArrayHasKey('getStripeConfiguration', $response);
        $config = $response['getStripeConfiguration'];

        self::assertNotEmpty($config['apiKey']);
        self::assertStringStartsWith('pk_test_', $config['apiKey']);
        self::assertNotEmpty($config['locale']);

        self::assertNotEmpty($config['appInfo']);
        self::assertNotEmpty($config['appInfo']['name']);
        self::assertNotEmpty($config['appInfo']['version']);
        self::assertNotEmpty($config['appInfo']['partner_id']);

        self::assertNotEmpty($config['options']);

        // elementsOptions is a JSON-serialized string; it must be valid JSON with at
        // least `mode` and `currency` so that the storefront can pass it to
        // `stripe.elements(options)`.
        self::assertNotEmpty($config['elementsOptions']);
        $elementsOptions = json_decode($config['elementsOptions'], true);
        self::assertIsArray($elementsOptions, 'elementsOptions should be a JSON object');
        self::assertArrayHasKey('mode', $elementsOptions);
        self::assertArrayHasKey('currency', $elementsOptions);
    }
}
