<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChangeSubscriptionPaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $stripeConfig;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testChangeSubscriptionPaymentMethodViaRestEndpoint()
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

        $subscriptionId = $order->getPayment()->getAdditionalInformation('subscription_id');
        $this->assertNotEmpty($subscriptionId, "Subscription ID should be set on the order payment");

        $stripeClient = $this->stripeConfig->getStripeClient();
        $subscription = $stripeClient->subscriptions->retrieve($subscriptionId);
        $this->assertEquals("active", $subscription->status);

        // Step 2: Retrieve pm_card_mastercard, attach it to the Stripe customer
        $paymentMethod = $stripeClient->paymentMethods->retrieve('pm_card_mastercard');
        $this->assertNotEmpty($paymentMethod->id);

        $stripeCustomer = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class)->getCustomerModel();
        $customerId = $stripeCustomer->getStripeId();
        $this->assertNotEmpty($customerId, "Customer should have a Stripe ID");

        $stripeClient->paymentMethods->attach($paymentMethod->id, ['customer' => $customerId]);

        // Step 3: Call the REST endpoint to change the subscription's default payment method
        $service = $this->objectManager->get(\StripeIntegration\Payments\Api\ServiceInterface::class);
        $result = $service->change_subscription_payment_method($subscriptionId, $paymentMethod->id);
        $this->assertNotEmpty($result);

        $resultData = json_decode($result, true);
        $this->assertTrue($resultData['success'], "The REST endpoint should return success");

        // Step 4: Emulate the payment_method.attached event
        $this->tests->event()->trigger("payment_method.attached", $paymentMethod->id);

        // Step 5: Fetch the subscription and verify the default payment method matches
        $updatedSubscription = $stripeClient->subscriptions->retrieve($subscriptionId);
        $this->assertEquals(
            $paymentMethod->id,
            $updatedSubscription->default_payment_method,
            "The subscription's default payment method should be updated to the Mastercard"
        );
    }
}
