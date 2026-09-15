<?php

namespace StripeIntegration\Payments\Helper;

class Dispute
{
    public const STRIPE_DISPUTE_STATUS_CODE = 'stripe_disputed';
    public const STRIPE_DISPUTE_STATE_CODE = 'stripe_disputed';

    public function isDisputeInquiry($disputeObject)
    {
        return $disputeObject['status'] === 'warning_closed' || $disputeObject['status'] === 'warning_needs_response' || $disputeObject['status'] === 'warning_under_review';
    }

    public function isChargeRefunded($charge, $disputeObject)
    {
        return $disputeObject['is_charge_refundable'] && $charge->refunded;
    }

    public function isDisputeLost($disputeObject)
    {
        return $disputeObject['status'] === 'lost';
    }

    public function isDisputeWon($disputeObject)
    {
        return $disputeObject['status'] === 'won';
    }

    public function isInquiryClosed($disputeObject)
    {
        return $disputeObject['status'] === 'warning_closed';
    }

    public function hasWithdrawnAmount($disputeObject)
    {
        foreach ($disputeObject['balance_transactions'] as $transaction) {
            if ($transaction['reporting_category'] === 'dispute') {
                return true;
            }
        }

        return false;
    }

    public function getFullWithdrawnAmount($disputeObject)
    {
        $fullWithdrawnAmount = 0;
        foreach ($disputeObject['balance_transactions'] as $transaction) {
            if ($transaction['reporting_category'] === 'dispute') {
                $fullWithdrawnAmount += $transaction['net'];
            }
        }

        return abs($fullWithdrawnAmount);
    }

    public function hasReinstatedAmount($disputeObject)
    {
        foreach ($disputeObject['balance_transactions'] as $transaction) {
            if ($transaction['reporting_category'] === 'dispute_reversal') {
                return true;
            }
        }

        return false;
    }

    public function getFullReinstatedAmount($disputeObject)
    {
        $fullWithdrawnAmount = 0;
        foreach ($disputeObject['balance_transactions'] as $transaction) {
            if ($transaction['reporting_category'] === 'dispute_reversal') {
                $fullWithdrawnAmount += $transaction['net'];
            }
        }

        return abs($fullWithdrawnAmount);
    }

}