<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\AccountPage;

use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Verifies the addStripePaymentMethod / listStripePaymentMethods / deleteStripePaymentMethod
 * mutations for a logged-in customer.
 */
class MySavedPaymentMethodTest extends GraphQlAbstract
{
    private const STORE_CODE = 'default';

    private CustomerTokenServiceInterface $customerTokenService;

    protected function setUp(): void
    {
        $this->customerTokenService = Bootstrap::getObjectManager()->get(CustomerTokenServiceInterface::class);
    }

    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoApiDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/saved_payment_method_customer.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture default_store payment/stripe_payments/save_payment_method 2
     */
    public function testSavedPaymentMethod(): void
    {
        $paymentMethodId = $this->addPaymentMethod();
        $this->assertSavedPaymentMethodCount(1);
        $this->deletePaymentMethod($paymentMethodId);
        $this->assertSavedPaymentMethodCount(0);
    }

    private function addPaymentMethod(): string
    {
        $query = <<<QUERY
mutation {
  addStripePaymentMethod(
    input: {
      payment_method: "pm_card_visa"
    }
  ) {
    id
    type
    brand
    exp_month
    exp_year
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaders());

        self::assertArrayHasKey('addStripePaymentMethod', $response);
        self::assertNotEmpty($response['addStripePaymentMethod']['id']);
        self::assertSame('card', $response['addStripePaymentMethod']['type']);
        self::assertSame('visa', $response['addStripePaymentMethod']['brand']);

        return $response['addStripePaymentMethod']['id'];
    }

    private function assertSavedPaymentMethodCount(int $expected): void
    {
        $query = <<<QUERY
mutation {
  listStripePaymentMethods {
    id
    type
    fingerprint
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaders());

        self::assertArrayHasKey('listStripePaymentMethods', $response);
        self::assertCount($expected, $response['listStripePaymentMethods'] ?? []);
    }

    private function deletePaymentMethod(string $paymentMethodId): void
    {
        $query = <<<QUERY
mutation {
  deleteStripePaymentMethod(
    input: {
      payment_method: "{$paymentMethodId}"
      fingerprint: null
    }
  )
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaders());

        self::assertArrayHasKey('deleteStripePaymentMethod', $response);
        self::assertNotEmpty($response['deleteStripePaymentMethod']);
    }

    private function getHeaders(): array
    {
        $token = $this->customerTokenService->createCustomerAccessToken('graphql@example.com', 'password');

        return ['Store' => self::STORE_CODE, 'Authorization' => 'Bearer ' . $token];
    }
}
