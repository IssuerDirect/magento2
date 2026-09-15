<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\TestFramework\Helper\Bootstrap;
use StripeIntegration\Payments\Model\Config as StripeConfig;
use StripeIntegration\Payments\Model\PaymentElement;
use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Verifies that a logged-in customer can change the payment method on an active
 * subscription via the `changeSubscriptionPaymentMethod` GraphQL mutation.
 */
class ChangeSubscriptionPaymentMethodTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoApiDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/saved_payment_method_customer.php
     * @magentoApiDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/subscription_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testChangeSubscriptionPaymentMethod(): void
    {
        $headers = $this->getCustomerHeaders();

        // Place an order for a virtual subscription using pm_card_visa.
        $cartId = $this->createEmptyCart($headers);
        $this->addSimpleProductToCart($cartId, 'virtual-monthly-subscription-product', 1, $headers);
        $this->setBillingAddressOnCart($cartId, $headers);
        $this->setStripePaymentMethodOnCart($cartId, 'pm_card_visa', false, $headers);
        $this->placeOrder($cartId, $headers);

        $subscriptionId = $this->getSubscriptionIdForMostRecentOrder();

        $stripeClient = Bootstrap::getObjectManager()->get(StripeConfig::class)->getStripeClient();
        $subscription = $stripeClient->subscriptions->retrieve($subscriptionId);
        self::assertSame('active', $subscription->status, 'Subscription should be active after placing the order');

        // Attach a second payment method so the customer can switch to it.
        $mastercard = $stripeClient->paymentMethods->retrieve('pm_card_mastercard');
        $stripeClient->paymentMethods->attach($mastercard->id, ['customer' => $subscription->customer]);

        $this->changeSubscriptionPaymentMethod($subscriptionId, $mastercard->id, $headers);

        $updated = $stripeClient->subscriptions->retrieve($subscriptionId);
        self::assertSame(
            $mastercard->id,
            $updated->default_payment_method,
            "Subscription's default payment method should be the newly attached Mastercard"
        );
    }

    private function getSubscriptionIdForMostRecentOrder(): string
    {
        $objectManager = Bootstrap::getObjectManager();

        $customer = $objectManager->get(CustomerRepositoryInterface::class)->get('graphql@example.com');

        /** @var OrderCollection $orderCollection */
        $orderCollection = $objectManager->create(OrderCollection::class);
        $orderCollection->addFieldToFilter('customer_id', $customer->getId());
        $orderCollection->setOrder('entity_id', 'DESC');
        $orderCollection->setPageSize(1);
        $order = $orderCollection->getFirstItem();

        self::assertNotEmpty($order->getId(), 'Order should exist after placing order');

        /** @var PaymentElement $paymentElement */
        $paymentElement = $objectManager->create(PaymentElement::class);
        $paymentElement->load($order->getQuoteId(), 'quote_id');
        $subscriptionId = $paymentElement->getSubscriptionId();

        self::assertNotEmpty($subscriptionId, 'Subscription ID should be set on the PaymentElement record');

        return $subscriptionId;
    }

    private function changeSubscriptionPaymentMethod(
        string $subscriptionId,
        string $paymentMethodId,
        array $headers
    ): void {
        $query = <<<QUERY
mutation {
  changeSubscriptionPaymentMethod(input: {
    subscription_id: "{$subscriptionId}"
    payment_method: "{$paymentMethodId}"
  })
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $headers);

        self::assertArrayHasKey('changeSubscriptionPaymentMethod', $response);
        self::assertTrue(
            $response['changeSubscriptionPaymentMethod'],
            'changeSubscriptionPaymentMethod mutation should return true on success'
        );
    }
}
