<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\PaymentLineItems;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class NormalTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_advanced_configuration/payment_line_items 1
     */
    public function testNormalCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntentId = $order->getPayment()->getLastTransId();

        // Retrieve the PI with expanded line_items
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['amount_details.line_items']]);

        // Assert that amount_details exists on the PI
        $this->assertNotEmpty($paymentIntent->amount_details, "amount_details should be present on the PaymentIntent");

        // Assert that line_items are present
        $this->assertNotEmpty($paymentIntent->amount_details->line_items->data, "line_items should be present in amount_details");

        // Compare order grand total with PI amount
        $expectedAmount = $this->tests->helper()->convertMagentoAmountToStripeAmount(
            $order->getGrandTotal(),
            $order->getOrderCurrencyCode()
        );
        $this->assertEquals($expectedAmount, $paymentIntent->amount, "PI amount should match order grand total");

        // Build expected line items from the order using the same model that PaymentIntent::getParamsFrom() uses
        $lineItemsModel = $this->objectManager->create(\StripeIntegration\Payments\Model\Payment\LineItems::class);
        $lineItemsModel->fromOrder($order);
        $expectedFormat = $lineItemsModel->getPaymentIntentFormat();

        // Assert that the format is not empty (validation passed)
        $this->assertNotEmpty($expectedFormat, "Line items should be generated for a normal order");
        $this->assertArrayHasKey('amount_details', $expectedFormat);
        $this->assertArrayHasKey('line_items', $expectedFormat['amount_details']);

        // Compare line items count
        $expectedLineItems = $expectedFormat['amount_details']['line_items'];
        $actualLineItems = $paymentIntent->amount_details->line_items->data;
        $this->assertCount(count($expectedLineItems), $actualLineItems, "Number of line items should match");

        // Compare each line item
        foreach ($expectedLineItems as $i => $expectedItem)
        {
            $actualItem = $actualLineItems[$i];

            $this->assertEquals($expectedItem['product_code'], $actualItem->product_code, "product_code should match for line item {$i}");
            $this->assertEquals($expectedItem['product_name'], $actualItem->product_name, "product_name should match for line item {$i}");
            $this->assertEquals($expectedItem['unit_cost'], $actualItem->unit_cost, "unit_cost should match for line item {$i}");
            $this->assertEquals($expectedItem['quantity'], $actualItem->quantity, "quantity should match for line item {$i}");
            $this->assertEquals($expectedItem['unit_of_measure'], $actualItem->unit_of_measure, "unit_of_measure should match for line item {$i}");

            // Compare tax if present
            if (isset($expectedItem['tax']))
            {
                $this->assertTrue(isset($actualItem->tax), "tax should be present for line item {$i}");
                $this->assertEquals($expectedItem['tax']['total_tax_amount'], $actualItem->tax->total_tax_amount, "tax amount should match for line item {$i}");
            }

            // Compare discount if present
            if (isset($expectedItem['discount_amount']))
            {
                $this->assertTrue(isset($actualItem->discount_amount), "discount_amount should be present for line item {$i}");
                $this->assertEquals($expectedItem['discount_amount'], $actualItem->discount_amount, "discount_amount should match for line item {$i}");
            }
        }

        // Compare shipping if present
        if (isset($expectedFormat['amount_details']['shipping']))
        {
            $this->assertTrue(isset($paymentIntent->amount_details->shipping), "shipping should be present on the PI");
            $this->assertEquals(
                $expectedFormat['amount_details']['shipping']['amount'],
                $paymentIntent->amount_details->shipping->amount,
                "shipping amount should match"
            );
        }

        // Compare tax if present at order level (when no per-item taxes)
        if (isset($expectedFormat['amount_details']['tax']))
        {
            $this->assertTrue(isset($paymentIntent->amount_details->tax), "tax should be present on the PI");
            $this->assertEquals(
                $expectedFormat['amount_details']['tax']['total_tax_amount'],
                $paymentIntent->amount_details->tax->total_tax_amount,
                "tax total should match"
            );
        }

        // Compare discount if present at order level (when no per-item discounts)
        if (isset($expectedFormat['amount_details']['discount_amount']))
        {
            $this->assertTrue(isset($paymentIntent->amount_details->discount_amount), "discount_amount should be present on the PI");
            $this->assertEquals(
                $expectedFormat['amount_details']['discount_amount'],
                $paymentIntent->amount_details->discount_amount,
                "discount amount should match"
            );
        }
    }
}
