<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\TrialSimple;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChangeShippingTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $request;
    private $stripeCustomer;
    private $checkoutSession;
    private $orderRepository;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
        $this->stripeCustomer = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class)->getCustomerModel();
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->orderRepository = $this->objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testChangeShippingAddress()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        // Place the order
        $order = $this->quote->placeOrder();
        $this->assertNotEmpty($order, "Order was not created");

        // Confirm the subscription
        $subscriptionId = $order->getPayment()->getAdditionalInformation('subscription_id');
        $this->assertNotEmpty($subscriptionId, "Subscription ID should be set");

        // Verify the subscription is in trialing status
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscriptionId);
        $this->assertEquals("trialing", $subscription->status, "Subscription should be in trialing status");

        $this->tests->event()->triggerSubscriptionEventsById($subscriptionId);

        // Fetch the customer's active subscriptions
        $subscriptions = $this->stripeCustomer->getSubscriptions();

        // Verify subscription exists (trialing subscriptions should appear)
        $this->assertGreaterThanOrEqual(1, count($subscriptions), "Should have at least one subscription");

        // Store original order shipping address for comparison
        $originalOrder = $this->orderRepository->get($order->getId());
        $originalShippingAddress = $originalOrder->getShippingAddress();
        $this->assertNotEmpty($originalShippingAddress, "Original order should have a shipping address");

        // Create an instance of the change shipping controller
        $changeShippingController = $this->objectManager->create(
            \StripeIntegration\Payments\Controller\Subscriptions\ChangeShipping::class
        );

        // Configure the request
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('form_key', 'testFormKey123');

        // Test CSRF validation
        $result = $changeShippingController->createCsrfValidationException($this->request);
        $this->assertNull($result, "CSRF validation exception should be null");

        $result = $changeShippingController->validateForCsrf($this->request);
        $this->assertFalse($result, "CSRF validation should fail with invalid form key");

        // Reset the quote
        $this->quote->reset();

        // Execute the controller
        $response = $changeShippingController->execute();

        // Verify the customer is redirected to checkout
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response, "Response should be a redirect");

        // Get the redirect URL
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('checkout', $redirectUrl, "Should redirect to checkout page");

        // Verify that the checkout session has the correct subscription products
        $checkoutQuote = $this->checkoutSession->getQuote();
        $this->assertNotEmpty($checkoutQuote->getAllItems(), "Checkout quote should have items");

        // Verify quote has been populated with subscription items
        $hasSubscriptionItems = false;
        foreach ($checkoutQuote->getAllItems() as $item) {
            if ($item->getProduct()->getId() == $order->getAllItems()[0]->getProduct()->getId()) {
                $hasSubscriptionItems = true;
                break;
            }
        }
        $this->assertTrue($hasSubscriptionItems, "Checkout quote should contain subscription items");

        // Test error handling: Try to update with an invalid subscription ID
        $this->request->setParam('subscription_id', 'invalid_subscription_id');
        $response = $changeShippingController->execute();

        // Should redirect to subscriptions page with error message
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('stripe/customer/subscriptions', $redirectUrl, "Should redirect to subscriptions page when subscription ID is invalid");
    }
}
