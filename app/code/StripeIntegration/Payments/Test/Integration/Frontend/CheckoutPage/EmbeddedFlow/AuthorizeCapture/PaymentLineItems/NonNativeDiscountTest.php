<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\PaymentLineItems;

/**
 * Tests the scenario where a non-native discount (reward points, gift cards, store credit)
 * causes a mismatch between line items total and grand total.
 *
 * Real-world scenario:
 * 1. Customer starts checkout → PI is created with amount_details matching the full price
 * 2. Customer applies reward points (non-native discount) → grand_total changes
 * 3. System tries to update PI with new amount, but line items validation fails
 *    because non-native discounts aren't in $order->getDiscountAmount()
 * 4. getPaymentIntentFormat() returns [] → amount_details not included in update params
 * 5. isInvalid() detects the PI has stale amount_details that can't be cleared via the API
 * 6. The PI is cancelled and a new one is created without stale data
 *
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class NonNativeDiscountTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * Tests that getPaymentIntentFormat() returns empty when a non-native discount
     * causes the line items total to mismatch the order grand total.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_advanced_configuration/payment_line_items 1
     */
    public function testLineItemsValidationFailsWithNonNativeDiscount()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Build line items from the order - with original grand total, validation should pass
        $lineItemsModel = $this->objectManager->create(\StripeIntegration\Payments\Model\Payment\LineItems::class);
        $lineItemsModel->fromOrder($order);
        $result = $lineItemsModel->getPaymentIntentFormat();
        $this->assertNotEmpty($result, "Line items should be generated for a normal order");
        $this->assertArrayHasKey('amount_details', $result);

        // Simulate a non-native discount (e.g. reward points worth $2.00) by reducing
        // grand_total without changing discount_amount. This is what Mageplaza Reward Points,
        // Amasty Gift Cards, and Store Credit modules do.
        $originalGrandTotal = $order->getGrandTotal();
        $order->setGrandTotal($originalGrandTotal - 2.00);

        // Rebuild line items with the modified order
        $lineItemsModel2 = $this->objectManager->create(\StripeIntegration\Payments\Model\Payment\LineItems::class);
        $lineItemsModel2->fromOrder($order);

        // Validation should fail: line items total != grand total
        $result2 = $lineItemsModel2->getPaymentIntentFormat();
        $this->assertEmpty($result2, "Line items should be empty when grand total doesn't match due to non-native discount");
    }

    /**
     * Tests that getUpdateableParams() filters out empty values for amount_details,
     * payment_details, and other params.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_advanced_configuration/payment_line_items 1
     */
    public function testUpdateableParamsFiltersEmptyValues()
    {
        $paymentIntentHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\PaymentIntent::class);

        // Create a real PI to use as the "existing" PI
        $stripe = $this->tests->stripe();
        $pi = $stripe->paymentIntents->create([
            'amount' => 5330,
            'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        // Params where line items validation has failed (no amount_details key at all)
        $params = [
            'amount' => 5130,
            'currency' => 'usd',
            'description' => 'Test order',
            'metadata' => ['Order #' => '100000001'],
        ];

        $updateableParams = $paymentIntentHelper->getUpdateableParams($params, $pi);

        // When amount_details is completely absent from params, it should not be in updateable params
        $this->assertContains('amount', $updateableParams, "amount should be updateable");
        $this->assertNotContains('amount_details', $updateableParams,
            "amount_details should be excluded when completely absent from params");

        // Empty string values for amount_details should be filtered out
        $params['amount_details'] = '';
        $updateableParams2 = $paymentIntentHelper->getUpdateableParams($params, $pi);
        $this->assertNotContains('amount_details', $updateableParams2,
            "amount_details='' should be filtered out because Stripe does not allow unsetting it");

        // Empty string values for payment_details should also be filtered out
        $params['payment_details'] = '';
        $updateableParams3 = $paymentIntentHelper->getUpdateableParams($params, $pi);
        $this->assertNotContains('payment_details', $updateableParams3,
            "payment_details='' should be filtered out because Stripe does not allow unsetting it");

        // Other params with empty values should also be filtered out
        $params['description'] = '';
        $updateableParams4 = $paymentIntentHelper->getUpdateableParams($params, $pi);
        $this->assertNotContains('description', $updateableParams4,
            "Empty params should be filtered out");

        // Clean up
        $stripe->paymentIntents->cancel($pi->id);
    }

    /**
     * Tests the Stripe API behavior: updating a PI's amount while stale amount_details
     * remains causes Stripe to reject the update with an error.
     *
     * Also verifies that cancelling and recreating the PI resolves the issue.
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysIsolated.php
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_advanced_configuration/payment_line_items 1
     */
    public function testStripeRejectsUpdateWithStaleAmountDetails()
    {
        // Place a real order and derive amount_details from its line items
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $currency = $order->getOrderCurrencyCode();
        $orderAmount = $helper->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);

        $lineItemsModel = $this->objectManager->create(\StripeIntegration\Payments\Model\Payment\LineItems::class);
        $lineItemsData = $lineItemsModel->fromOrder($order)->getPaymentIntentFormat();
        $this->assertNotEmpty($lineItemsData, "Line items should be generated for a normal order");

        $stripe = $this->tests->stripe();

        // Step 1: Create a PI with amount_details derived from the order
        $pi = $stripe->paymentIntents->create([
            'amount' => $orderAmount,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => true],
            'amount_details' => $lineItemsData['amount_details'],
        ]);

        $this->assertEquals('requires_payment_method', $pi->status);

        // Step 2: Try to update PI with a different amount but WITHOUT clearing amount_details.
        // Stripe should reject it because the stale amount_details doesn't match the new amount.
        $reducedAmount = $orderAmount - 200; // Simulate a $2.00 non-native discount
        $exceptionThrown = false;
        try {
            $stripe->paymentIntents->update($pi->id, [
                'amount' => $reducedAmount,
            ]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString("amount_details", $e->getMessage(),
                "Stripe should reject the update because stale amount_details doesn't match new amount");
        }

        $this->assertTrue($exceptionThrown,
            "Stripe should reject PI update when amount changes but stale amount_details remains");

        // Step 3: Verify the fix - cancel the PI and create a new one without amount_details
        $stripe->paymentIntents->cancel($pi->id);
        $newPI = $stripe->paymentIntents->create([
            'amount' => $reducedAmount,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        $this->assertEquals($reducedAmount, $newPI->amount,
            "New PI should have the correct amount without stale amount_details");

        // Clean up
        $stripe->paymentIntents->cancel($newPI->id);
    }

    /**
     * Tests that isInvalid() detects stale amount_details on a PI and triggers
     * invalidation so a new PI is created without the stale data.
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysIsolated.php
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_advanced_configuration/payment_line_items 1
     */
    public function testStaleAmountDetailsInvalidatesPaymentIntent()
    {
        // Place a real order and derive amount_details from its line items
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $currency = $order->getOrderCurrencyCode();
        $orderAmount = $helper->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);

        $lineItemsModel = $this->objectManager->create(\StripeIntegration\Payments\Model\Payment\LineItems::class);
        $lineItemsData = $lineItemsModel->fromOrder($order)->getPaymentIntentFormat();
        $this->assertNotEmpty($lineItemsData, "Line items should be generated for a normal order");

        $paymentIntentModel = $this->objectManager->create(\StripeIntegration\Payments\Model\PaymentIntent::class);
        $stripe = $this->tests->stripe();

        // Create a PI with amount_details derived from the order, then retrieve with expanded line_items
        $pi = $stripe->paymentIntents->create([
            'amount' => $orderAmount,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => true],
            'amount_details' => $lineItemsData['amount_details']
        ]);

        $pi = $stripe->paymentIntents->retrieve($pi->id, ['expand' => ['amount_details.line_items']]);

        $this->assertNotEmpty($pi->amount_details->line_items->data, "PI should have amount_details.line_items set");

        // Params without amount_details (simulates non-native discount causing line items validation to fail)
        $reducedAmount = $orderAmount - 200;
        $params = [
            'amount' => $reducedAmount,
            'currency' => strtolower($currency),
            'description' => 'Test order',
            'metadata' => ['Order #' => $order->getIncrementId()],
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];

        // isInvalid should return true because the PI has stale amount_details that can't be cleared
        $isInvalid = $paymentIntentModel->isInvalid($params, null, null, $pi);
        $this->assertTrue($isInvalid,
            "PI with stale amount_details should be invalid when new params don't include amount_details");

        // If params include amount_details (line items validation passed), PI should not be invalid
        $params['amount_details'] = $lineItemsData['amount_details'];

        $isInvalid2 = $paymentIntentModel->isInvalid($params, null, null, $pi);
        $this->assertFalse($isInvalid2,
            "PI should not be invalid when new params include amount_details");

        // Clean up
        $stripe->paymentIntents->cancel($pi->id);
    }
}
