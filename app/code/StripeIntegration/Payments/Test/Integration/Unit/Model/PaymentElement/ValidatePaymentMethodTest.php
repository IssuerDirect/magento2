<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model\PaymentElement;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ValidatePaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $paymentElement;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
    }

    private function createPaymentMethod()
    {
        return $this->tests->stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPaymentMethodNotAttachedToCustomer()
    {
        $paymentMethod = $this->createPaymentMethod();

        // Should not throw — PM has no customer
        $this->paymentElement->validatePaymentMethod($paymentMethod->id);
        $this->addToAssertionCount(1);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPaymentMethodBelongsToSameCustomer()
    {
        $customer = $this->tests->stripe()->customers->create([
            'email' => 'test-validate-pm@example.com',
        ]);

        $paymentMethod = $this->createPaymentMethod();
        $this->tests->stripe()->paymentMethods->attach($paymentMethod->id, [
            'customer' => $customer->id
        ]);

        // Set up the customer model so getStripeId() returns the same customer
        $customerModel = $this->tests->helper()->getCustomerModel();
        $customerModel->setStripeId($customer->id);

        // Should not throw — PM belongs to this customer
        $this->paymentElement->validatePaymentMethod($paymentMethod->id);
        $this->addToAssertionCount(1);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPaymentMethodBelongsToDifferentCustomer()
    {
        $customerA = $this->tests->stripe()->customers->create([
            'email' => 'customer-a@example.com',
        ]);

        $customerB = $this->tests->stripe()->customers->create([
            'email' => 'customer-b@example.com',
        ]);

        $paymentMethod = $this->createPaymentMethod();
        $this->tests->stripe()->paymentMethods->attach($paymentMethod->id, [
            'customer' => $customerA->id
        ]);

        // Set up the customer model to be customer B
        $customerModel = $this->tests->helper()->getCustomerModel();
        $customerModel->setStripeId($customerB->id);

        // Should throw — PM belongs to customer A but current customer is B
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("This payment method cannot be used.");
        $this->paymentElement->validatePaymentMethod($paymentMethod->id);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPaymentMethodAttachedButNoLocalCustomer()
    {
        $customer = $this->tests->stripe()->customers->create([
            'email' => 'attached-pm@example.com',
        ]);

        $paymentMethod = $this->createPaymentMethod();
        $this->tests->stripe()->paymentMethods->attach($paymentMethod->id, [
            'customer' => $customer->id
        ]);

        // Don't set any Stripe ID on the local customer model — getStripeId() returns null
        // Should not throw because the local customer has no Stripe ID
        $this->paymentElement->validatePaymentMethod($paymentMethod->id);
        $this->addToAssertionCount(1);
    }
}
