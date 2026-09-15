<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ZeroAmount;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiringCouponOnceTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ZeroAmount")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent_apply_once")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);

        //Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        //The subscription setup is correct.
        $subscription = $customer->subscriptions->data[0];
        $this->compare->object($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => 1083,
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "plan" => [
                "amount" => 1083,
                "currency" => "usd",
                "interval" => "month",
                "interval_count" => 1
            ],
            "status" => "trialing"
        ]);

        // Discount checks
        $this->assertCount(1, $subscription->discounts);

        // Customer has no charges
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(0, $charges->data);

        // Upcoming invoice for subscription
        $upcomingInvoice = $this->tests->stripe()->invoices->createPreview(['subscription' => $customer->subscriptions->data[0]->id]);

        // Upcoming invoice has a discount and due amount is discounted (same as order grand total)
        $this->assertCount(1, $upcomingInvoice->discounts);
        $this->compare->object($upcomingInvoice, [
            "amount_due" => 974, // Discounted amount
            "amount_paid" => 0,
            "amount_remaining" => 974,
            "total" => 974
        ]);

        // Discount checks
        $this->assertCount(1, $upcomingInvoice->discounts);

        // State of order is proper.
        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());
        $magentoInvoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($magentoInvoice->getState(), Invoice::STATE_PAID);
        $this->assertEquals(0, $magentoInvoice->getGrandTotal());

        // End the trial
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->endTrialSubscription($subscription->id);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // New order has invoice in paid status
        $newOrder = $this->tests->getLastOrder();
        $this->assertEquals($newOrder->getInvoiceCollection()->getFirstItem()->getState(), Invoice::STATE_PAID);
        $this->assertEquals(0, $newOrder->getTotalDue());
        $this->assertEquals("9.7400", $newOrder->getGrandTotal());
        $this->assertEquals("complete", $newOrder->getState());
        $this->assertEquals("complete", $newOrder->getStatus());

        // Order has no credit memos
        $this->assertCount(0, $order->getCreditmemosCollection());
    }
}
