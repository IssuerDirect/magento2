<?php

namespace StripeIntegration\Payments\Plugin\Sales\Block\Adminhtml\Order\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;

class View
{
    public function afterSetLayout(
        \Magento\Sales\Block\Adminhtml\Order\Creditmemo\View $subject,
        $result
    ) {
        $creditmemo = $subject->getCreditmemo();

        if (!$creditmemo || $creditmemo->getState() != Creditmemo::STATE_OPEN) {
            return $result;
        }

        $order = $creditmemo->getOrder();
        $paymentMethod = $order->getPayment()->getMethod();

        if (strpos($paymentMethod, 'stripe_') !== 0) {
            return $result;
        }

        $subject->removeButton('cancel');
        $subject->removeButton('refund');

        return $result;
    }
}
