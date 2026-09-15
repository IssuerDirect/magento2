<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order;

use Magento\Sales\Model\Order;

class StateResolver
{
    private $refundsHelper;
    private $previousState;

    public function __construct(
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper
    )
    {
        $this->refundsHelper = $refundsHelper;
    }

    public function beforeCheck(
        \Magento\Sales\Model\ResourceModel\Order\Handler\State $subject,
        Order $order
    )
    {
        $this->previousState = $order->getState();

        return null;
    }

    public function afterCheck(
        \Magento\Sales\Model\ResourceModel\Order\Handler\State $subject,
        $result,
        Order $order
    )
    {
        if (!$this->previousState)
        {
            return $result;
        }

        $payment = $order->getPayment();
        if (!$payment || strpos($payment->getMethod(), 'stripe_') !== 0)
        {
            return $result;
        }

        if ($this->refundsHelper->isLastRefundPending())
        {
            // If a refund was just created and is pending, keep the order in its previous state.
            // We use the default status for that state because Payment::refund() may have already
            // set the status to one matching a completed state via addStatusHistoryComment().
            $order->setState($this->previousState);
            $order->setStatus($order->getConfig()->getStateDefaultStatus($this->previousState));
        }

        return $result;
    }
}
