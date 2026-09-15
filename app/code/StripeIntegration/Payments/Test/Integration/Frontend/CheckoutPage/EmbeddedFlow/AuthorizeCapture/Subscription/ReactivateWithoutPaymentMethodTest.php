<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ReactivateWithoutPaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $stripeConfig;
    private $subscriptionFactory;
    private $request;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->subscriptionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\SubscriptionFactory::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testReactivateWithoutPaymentMethod()
    {
        // Step 1: Place a subscription order
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("SubscriptionSingle")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->tests->confirmSubscription($order);

        // Verify the subscription is active
        $stripeCustomer = $this->tests->helper()->getCustomerModel();
        $subscriptions = $stripeCustomer->getSubscriptions();
        $this->assertEquals(1, count($subscriptions));
        $subscription = array_pop($subscriptions);
        $this->assertEquals("active", $subscription->status);
        $subscriptionId = $subscription->id;

        // Step 2: Cancel the subscription
        $subscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
        $this->assertNotEmpty($subscriptionModel);

        $cancelController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Subscriptions\Cancel::class);
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('form_key', 'testFormKey123');
        $cancelController->execute();

        $canceledSubscription = $this->stripeConfig->getStripeClient()->subscriptions->retrieve($subscriptionId, []);
        $this->assertEquals('canceled', $canceledSubscription->status);

        // Step 3: Delete all payment methods for the customer
        $stripeClient = $this->stripeConfig->getStripeClient();
        $customerId = $stripeCustomer->getStripeId();

        $paymentMethods = $stripeClient->paymentMethods->all([
            'customer' => $customerId,
            'type' => 'card'
        ]);

        foreach ($paymentMethods->data as $paymentMethod) {
            $stripeClient->paymentMethods->detach($paymentMethod->id);
        }

        // Verify all payment methods were deleted
        $paymentMethods = $stripeClient->paymentMethods->all([
            'customer' => $customerId,
            'type' => 'card'
        ]);
        $this->assertEquals(0, count($paymentMethods->data), 'All payment methods should be deleted');

        // Clear the quote so the reactivation code builds a fresh one
        $this->quote->reset();

        // Step 4: Attempt to reactivate — should redirect to checkout
        $reactivateController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Subscriptions\Reactivate::class);
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('form_key', 'testFormKey123');
        $response = $reactivateController->execute();

        // Verify redirect to checkout page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('checkout', $redirectUrl);

        // Step 5: Verify the checkout session has subscription reactivation details
        $checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $subscriptionReactivateDetails = $checkoutSession->getSubscriptionReactivateDetails();
        $this->assertNotEmpty($subscriptionReactivateDetails, 'Subscription reactivate details should be stored in session');
        $this->assertEquals($subscriptionId, $subscriptionReactivateDetails['update_subscription_id']);
        $this->assertArrayHasKey('subscription_data', $subscriptionReactivateDetails);
        $this->assertArrayHasKey('success_url', $subscriptionReactivateDetails);

        // Verify the subscription_data contains the original subscription params without a default_payment_method
        $subscriptionData = $subscriptionReactivateDetails['subscription_data'];
        $this->assertArrayNotHasKey('default_payment_method', $subscriptionData, 'No default payment method should be set since all were deleted');
        $this->assertEquals($customerId, $subscriptionData['customer']);
        $this->assertNotEmpty($subscriptionData['items']);

        // Step 6: Verify the fresh quote was created with the subscription product
        $freshQuote = $checkoutSession->getQuote();
        $this->assertTrue($freshQuote->hasItems(), 'The reactivation quote should have items');
        $this->assertGreaterThan(0, $freshQuote->getItemsCount());

        // Verify the shipping address was copied from the original order
        $shippingAddress = $freshQuote->getShippingAddress();
        $this->assertNotEmpty($shippingAddress->getShippingMethod(), 'Shipping method should be set from original order');

        // Verify the CheckoutSession helper recognizes this as a reactivation
        $checkoutSessionHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\CheckoutSession::class);
        $this->assertTrue($checkoutSessionHelper->isSubscriptionReactivate());

        // Step 7: Complete checkout with a new payment method to reactivate the subscription
        // The fresh quote needs the customer properly assigned for order placement
        $customerRepository = $this->objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customer = $customerRepository->get('customer@example.com');
        $freshQuote->assignCustomer($customer);

        // Set billing address data (without overwriting customer email)
        $addressHelper = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
        $billingAddressData = $addressHelper->getMagentoFormat("California");
        unset($billingAddressData['email']); // Don't override customer email
        $freshQuote->getBillingAddress()->addData($billingAddressData);
        $freshQuote->getBillingAddress()->setCustomerAddressId(null); // Clear invalid address ID

        // Clean up copied address IDs from the original order that may no longer be valid
        $freshQuote->getShippingAddress()->setEmail(null);
        $freshQuote->getShippingAddress()->setCustomerAddressId(null);

        $this->quote
            ->setQuote($freshQuote)
            ->setPaymentMethod("SuccessCard");

        $reactivationOrder = $this->quote->placeOrder();
        $this->tests->confirmSubscription($reactivationOrder);

        // Step 8: Verify the subscription was reactivated
        $subscriptions = $stripeCustomer->getSubscriptions();
        $this->assertEquals(1, count($subscriptions));
        $reactivatedSubscription = array_pop($subscriptions);
        $this->assertEquals('active', $reactivatedSubscription->status);

        // The reactivated subscription should be a new one (different ID from the canceled one)
        $this->assertNotEquals($subscriptionId, $reactivatedSubscription->id);

        // Verify the old subscription model was marked as reactivated
        $oldSubscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
        $this->assertEquals('reactivated', $oldSubscriptionModel->getStatus());

        // Verify the new subscription has a payment method (the new card)
        $this->assertNotEmpty($reactivatedSubscription->default_payment_method, 'Reactivated subscription should have a new payment method');

        // Verify the reactivation order has subscription data
        $reactivationPayment = $reactivationOrder->getPayment();
        $this->assertNotEmpty($reactivationPayment->getAdditionalInformation('subscription_id'));
    }
}
