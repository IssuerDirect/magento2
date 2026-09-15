<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use StripeIntegration\Payments\Helper\Dispute;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DualDisputeTest extends \PHPUnit\Framework\TestCase
{
    private $tests;
    private $quote;

    public function setUp(): void
    {
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    private function placeOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);
        $order = $this->tests->refreshOrder($order);

        return [$order, $paymentIntent];
    }

    /**
     * Two disputes created, both won. Order should return to processing.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testDualDisputeBothWon()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $this->assertEquals("processing", $order->getState());

        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;

        // Two disputes created
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(Dispute::STRIPE_DISPUTE_STATE_CODE, $order->getState());
        $this->assertEquals("processing", $order->getHoldBeforeState());

        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_002', $chargeId, $piId, 3330, 'usd', 'product_not_received', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(Dispute::STRIPE_DISPUTE_STATE_CODE, $order->getState());
        $this->assertEquals("processing", $order->getHoldBeforeState(), "Second dispute must not overwrite holdBeforeState");

        // Both disputes won
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'won', true));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());

        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_002', $chargeId, $piId, 3330, 'usd', 'product_not_received', 'won', true));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
    }

    /**
     * Two disputes created, first won, second lost for a partial amount.
     * Order should return to processing with a manual credit memo comment.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testDualDisputeFirstWonSecondLostPartial()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;

        // Two disputes created
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'needs_response'));
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_002', $chargeId, $piId, 1500, 'usd', 'product_not_received', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getHoldBeforeState());

        // First dispute won
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'won', true));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());

        // Second dispute lost (partial amount — $15.00 of $53.30 order)
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_002', $chargeId, $piId, 1500, 'usd', 'product_not_received', 'lost'));
        $order = $this->tests->refreshOrder($order);

        // Order stays in processing — partial loss requires manual credit memo
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize(), "No automatic credit memo for partial disputes");
    }

    /**
     * Two disputes created, first won, second lost for the full order amount.
     * The full-amount loss should create an offline credit memo and close the order.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testDualDisputeFirstWonSecondLostFullAmount()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;
        $orderTotal = (int) round($order->getGrandTotal() * 100); // 5330

        // Two disputes created
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'needs_response'));
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_002', $chargeId, $piId, $orderTotal, 'usd', 'product_not_received', 'needs_response'));

        // First dispute won
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'won', true));

        // Second dispute lost for the full order amount → auto credit memo
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_002', $chargeId, $piId, $orderTotal, 'usd', 'product_not_received', 'lost'));
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("closed", $order->getState());
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize(), "Full-amount loss should create an offline credit memo");
    }

    /**
     * Two disputes created, both lost for partial amounts.
     * Order should return to processing with manual credit memo comments.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testDualDisputeBothLostPartial()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;

        // Two disputes created
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'needs_response'));
        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_002', $chargeId, $piId, 1500, 'usd', 'product_not_received', 'needs_response'));

        // Both disputes lost (partial amounts)
        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'lost'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize());

        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_002', $chargeId, $piId, 1500, 'usd', 'product_not_received', 'lost'));
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize(), "Partial disputes should not create automatic credit memos");
    }

    /**
     * Single dispute won — basic baseline test.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testSingleDisputeWon()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;

        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(Dispute::STRIPE_DISPUTE_STATE_CODE, $order->getState());
        $this->assertEquals("processing", $order->getHoldBeforeState());

        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 2000, 'usd', 'fraudulent', 'won', true));
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
    }

    /**
     * Single dispute lost for the full order amount — auto credit memo, order closed.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testSingleDisputeLostFullAmount()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;
        $orderTotal = (int) round($order->getGrandTotal() * 100);

        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, $orderTotal, 'usd', 'fraudulent', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(Dispute::STRIPE_DISPUTE_STATE_CODE, $order->getState());

        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, $orderTotal, 'usd', 'fraudulent', 'lost'));
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("closed", $order->getState());
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize(), "Full-amount loss should create an offline credit memo");
    }

    /**
     * Single dispute lost for a partial amount — no auto credit memo, order returns to processing.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testSingleDisputeLostPartialAmount()
    {
        [$order, $paymentIntent] = $this->placeOrder();
        $chargeId = $paymentIntent->latest_charge->id;
        $piId = $paymentIntent->id;

        $this->tests->event()->trigger("charge.dispute.created", $this->buildDisputeObject('dp_001', $chargeId, $piId, 1500, 'usd', 'fraudulent', 'needs_response'));
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(Dispute::STRIPE_DISPUTE_STATE_CODE, $order->getState());

        $this->tests->event()->trigger("charge.dispute.closed", $this->buildDisputeObject('dp_001', $chargeId, $piId, 1500, 'usd', 'fraudulent', 'lost'));
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize(), "Partial loss should not create automatic credit memos");
    }

    /**
     * Build a mock dispute object array that matches the Stripe dispute structure.
     */
    private function buildDisputeObject(
        string $disputeId,
        string $chargeId,
        string $paymentIntentId,
        int $amount,
        string $currency,
        string $reason,
        string $status,
        bool $hasReinstatedAmount = false
    ): array {
        $balanceTransactions = [];

        // Dispute always has a withdrawal transaction
        $balanceTransactions[] = [
            'id' => 'txn_withdrawal_' . $disputeId,
            'object' => 'balance_transaction',
            'amount' => -$amount,
            'currency' => $currency,
            'net' => -($amount + 1500), // amount + $15 fee
            'fee' => 1500,
            'reporting_category' => 'dispute',
            'type' => 'adjustment',
        ];

        if ($hasReinstatedAmount) {
            $balanceTransactions[] = [
                'id' => 'txn_reinstatement_' . $disputeId,
                'object' => 'balance_transaction',
                'amount' => $amount,
                'currency' => $currency,
                'net' => $amount + 1500,
                'fee' => 0,
                'reporting_category' => 'dispute_reversal',
                'type' => 'adjustment',
            ];
        }

        return [
            'id' => $disputeId,
            'object' => 'dispute',
            'amount' => $amount,
            'balance_transactions' => $balanceTransactions,
            'charge' => $chargeId,
            'currency' => $currency,
            'evidence_details' => [
                'due_by' => time() + 86400 * 21,
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'is_charge_refundable' => false,
            'livemode' => false,
            'metadata' => [],
            'payment_intent' => $paymentIntentId,
            'payment_method_details' => [
                'type' => 'card',
                'card' => [
                    'brand' => 'visa',
                    'case_type' => 'chargeback',
                    'network_reason_code' => '10.4',
                ],
            ],
            'reason' => $reason,
            'status' => $status,
        ];
    }
}
