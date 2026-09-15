<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChangePaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $stripeConfig;
    private $request;
    private $stripeCustomer;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
        $this->stripeCustomer = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class)->getCustomerModel();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testChangePaymentMethod()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("SubscriptionSingle")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Confirm the subscription
        $this->tests->confirmSubscription($order);
        $subscriptionId = $order->getPayment()->getAdditionalInformation('subscription_id');

        // Fetch the customer's active subscriptions
        $subscriptions = $this->stripeCustomer->getSubscriptions();

        // Verify subscription is active
        $this->assertEquals(1, count($subscriptions));
        $subscription = array_pop($subscriptions);
        $this->assertNotEmpty($subscription);
        $this->assertEquals("active", $subscription->status);

        // Get the current payment method ID
        $currentPaymentMethodId = $subscription->default_payment_method;
        $this->assertNotEmpty($currentPaymentMethodId, "Default payment method should be set");

        $stripeClient = $this->stripeConfig->getStripeClient();
        $customerId = $this->stripeCustomer->getStripeId();

        // Create and confirm a setup intent for the Visa test card.
        // allow_redirects=never ensures the intent settles synchronously in test mode.
        $setupIntent = $stripeClient->setupIntents->create([
            'customer' => $customerId,
            'payment_method' => 'pm_card_visa',
            'confirm' => true,
            'usage' => 'off_session',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never'
            ],
        ]);

        $this->assertEquals('succeeded', $setupIntent->status, "Setup intent should be confirmed");
        $newPaymentMethodId = $setupIntent->payment_method;
        $this->assertNotEmpty($newPaymentMethodId, "Setup intent should have a payment method");

        // Create an instance of the change payment method controller
        $changePaymentMethodController = $this->objectManager->create(
            \StripeIntegration\Payments\Controller\Subscriptions\ChangePaymentMethod::class
        );

        // Configure the request with the setup intent ID (as Stripe would append to the return_url)
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('setup_intent', $setupIntent->id);

        // Execute the controller
        $response = $changePaymentMethodController->execute();

        // Verify the customer is redirected to the subscriptions page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // Validate that the subscription now has the new payment method
        $updatedSubscription = $stripeClient->subscriptions->retrieve($subscriptionId, []);
        $this->assertEquals($newPaymentMethodId, $updatedSubscription->default_payment_method, "Subscription should have the new Visa payment method");

        // Verify the redirect URL
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('stripe/customer/subscriptions', $redirectUrl);

        // Test error handling: pass an invalid setup intent ID
        $this->request->setParam('setup_intent', 'seti_invalid_test_id');
        $response = $changePaymentMethodController->execute();

        // Should still redirect to subscriptions page but with an error message
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // The valid payment method from the successful update should still be set
        $finalSubscription = $stripeClient->subscriptions->retrieve($subscriptionId, []);
        $this->assertEquals($newPaymentMethodId, $finalSubscription->default_payment_method);
    }
}
