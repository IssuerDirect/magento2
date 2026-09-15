<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\TestFramework\Helper\Bootstrap;
use StripeIntegration\Payments\Test\Functional\GraphQL\AbstractCheckoutTestCase;

/**
 * Verifies that a customer placing an order with a 3DS card gets the expected GraphQL
 * response. The order is placed in `pending_payment` status with a `client_secret`
 * returned so the storefront can complete 3DS via `stripe.handleNextAction()`.
 */
class ThreeDSPlaceOrderTest extends AbstractCheckoutTestCase
{
    /**
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeys.php
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     */
    public function testStripePlaceOrder(): void
    {
        $headers = $this->getCustomerHeaders('customer@example.com', 'password');

        $cartId = $this->createEmptyCart($headers);
        $this->addSimpleProductToCart($cartId, 'simple_product', 1, $headers);
        $this->setShippingAddressOnCart($cartId, $headers);
        $this->setBillingAddressOnCart($cartId, $headers);
        $this->setFlatRateShippingMethod($cartId, $headers);
        $this->setStripePaymentMethodOnCart($cartId, 'pm_card_authenticationRequired', true, $headers);

        $order = $this->placeOrder($cartId, $headers);

        // 3DS: the storefront must complete authentication client-side, so the resolver
        // injects the PaymentIntent client secret into the response.
        self::assertNotEmpty(
            $order['client_secret'] ?? null,
            'placeOrder must return a client_secret for 3DS-protected cards'
        );

        // The order is placed in pending_payment state; finalization happens after the
        // charge.succeeded webhook arrives.
        $orderModel = $this->loadOrderByIncrementId($order['order_number']);
        self::assertSame('pending_payment', $orderModel->getState());
        self::assertSame('pending_payment', $orderModel->getStatus());
    }

    private function loadOrderByIncrementId(string $incrementId): OrderInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $objectManager->create(SearchCriteriaBuilder::class);
        /** @var OrderRepositoryInterface $orderRepository */
        $orderRepository = $objectManager->get(OrderRepositoryInterface::class);

        $searchCriteria = $searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->create();
        $items = $orderRepository->getList($searchCriteria)->getItems();

        self::assertNotEmpty($items, "Order {$incrementId} was not persisted");

        return array_values($items)[0];
    }
}
