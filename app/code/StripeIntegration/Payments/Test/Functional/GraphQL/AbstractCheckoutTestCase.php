<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL;

use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Shared helpers for GraphQL functional tests that drive a checkout flow.
 *
 * Subclasses call these helpers to build a cart, set addresses and payment method, and
 * place the order. Each helper runs a single GraphQL mutation and keeps assertions minimal
 * (only what is necessary to know the mutation succeeded), leaving the test-specific
 * assertions to the subclass.
 */
abstract class AbstractCheckoutTestCase extends GraphQlAbstract
{
    protected const STORE_CODE = 'default';

    private ?CustomerTokenServiceInterface $customerTokenService = null;

    /**
     * Build the HTTP header map for an authenticated customer request.
     */
    protected function getCustomerHeaders(
        string $email = 'graphql@example.com',
        string $password = 'password'
    ): array {
        if ($this->customerTokenService === null) {
            $this->customerTokenService = Bootstrap::getObjectManager()->get(CustomerTokenServiceInterface::class);
        }

        $token = $this->customerTokenService->createCustomerAccessToken($email, $password);

        return ['Store' => self::STORE_CODE, 'Authorization' => 'Bearer ' . $token];
    }

    protected function createEmptyCart(array $headers = []): string
    {
        $query = <<<QUERY
mutation {
  createEmptyCart
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $headers);
        self::assertArrayHasKey('createEmptyCart', $response);
        self::assertNotEmpty($response['createEmptyCart']);

        return $response['createEmptyCart'];
    }

    protected function addSimpleProductToCart(
        string $cartId,
        string $sku,
        float $quantity = 1,
        array $headers = []
    ): void {
        $query = <<<QUERY
mutation {
  addSimpleProductsToCart(
    input: {
      cart_id: "{$cartId}"
      cart_items: [
        {
          data: {
            quantity: {$quantity}
            sku: "{$sku}"
          }
        }
      ]
    }
  ) {
    cart {
      items {
        quantity
        product {
          sku
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    protected function setGuestEmailOnCart(string $cartId, string $email, array $headers = []): void
    {
        $query = <<<QUERY
mutation {
  setGuestEmailOnCart(input: {
    cart_id: "{$cartId}"
    email: "{$email}"
  }) {
    cart {
      email
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    protected function setShippingAddressOnCart(string $cartId, array $headers = []): void
    {
        $query = <<<QUERY
mutation {
  setShippingAddressesOnCart(
    input: {
      cart_id: "{$cartId}"
      shipping_addresses: [
        {
          address: {
            firstname: "John"
            lastname: "Doe"
            company: "Company Name"
            street: ["3320 N Crescent Dr", "Beverly Hills"]
            city: "Los Angeles"
            region: "CA"
            region_id: 12
            postcode: "90210"
            country_code: "US"
            telephone: "123-456-0000"
            save_in_address_book: false
          }
        }
      ]
    }
  ) {
    cart {
      shipping_addresses {
        firstname
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    protected function setBillingAddressOnCart(string $cartId, array $headers = []): void
    {
        $query = <<<QUERY
mutation {
  setBillingAddressOnCart(
    input: {
      cart_id: "{$cartId}"
      billing_address: {
        address: {
          firstname: "John"
          lastname: "Doe"
          company: "Company Name"
          street: ["64 Strawberry Dr", "Beverly Hills"]
          city: "Los Angeles"
          region: "CA"
          region_id: 12
          postcode: "90210"
          country_code: "US"
          telephone: "123-456-0000"
          save_in_address_book: false
        }
      }
    }
  ) {
    cart {
      billing_address {
        firstname
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    protected function setFlatRateShippingMethod(string $cartId, array $headers = []): void
    {
        $query = <<<QUERY
mutation {
  setShippingMethodsOnCart(input: {
    cart_id: "{$cartId}"
    shipping_methods: [
      {
        carrier_code: "flatrate"
        method_code: "flatrate"
      }
    ]
  }) {
    cart {
      shipping_addresses {
        selected_shipping_method {
          carrier_code
          method_code
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    protected function setStripePaymentMethodOnCart(
        string $cartId,
        string $paymentMethodId,
        bool $savePaymentMethod = false,
        array $headers = []
    ): void {
        $saveFlag = $savePaymentMethod ? 'true' : 'false';
        $query = <<<QUERY
mutation {
  setPaymentMethodOnCart(input: {
    cart_id: "{$cartId}"
    payment_method: {
      code: "stripe_payments"
      stripe_payments: {
        payment_method: "{$paymentMethodId}"
        save_payment_method: {$saveFlag}
      }
    }
  }) {
    cart {
      selected_payment_method {
        code
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $headers);
    }

    /**
     * Run the placeOrder mutation and return the raw order payload.
     *
     * @return array{order_number:string, client_secret:?string}
     */
    protected function placeOrder(string $cartId, array $headers = []): array
    {
        $query = <<<QUERY
mutation {
  placeOrder(input: {cart_id: "{$cartId}"}) {
    order {
      order_number
      client_secret
    }
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $headers);
        self::assertArrayHasKey('placeOrder', $response);
        self::assertArrayHasKey('order', $response['placeOrder']);
        self::assertArrayHasKey('order_number', $response['placeOrder']['order']);
        self::assertNotEmpty($response['placeOrder']['order']['order_number']);

        return $response['placeOrder']['order'];
    }
}
