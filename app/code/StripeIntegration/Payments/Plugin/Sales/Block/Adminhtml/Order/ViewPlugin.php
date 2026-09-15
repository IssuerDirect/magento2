<?php

namespace StripeIntegration\Payments\Plugin\Sales\Block\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use StripeIntegration\Payments\Helper\Radar;

class ViewPlugin
{
    private $authorization;

    public function __construct(
        AuthorizationInterface $authorization
    ) {
        $this->authorization = $authorization;
    }

    public function beforeSetLayout(View $subject)
    {
        $order = $subject->getOrder();

        if ($order->getState() === Radar::MANUAL_REVIEW_STATE_CODE &&
            $order->getStatus() === Radar::MANUAL_REVIEW_STATUS_CODE
        ) {
            if ($this->authorization->isAllowed('StripeIntegration_Payments::accept_reject_manual_review')) {
                $subject->addButton(
                    'accept_review',
                    [
                        'label' => __('Approve'),
                        'onclick' => 'setLocation(\'' . $subject->getUrl('stripe_payments_admin/order/acceptReview') . '\')',
                        'class' => 'accept-review'
                    ]
                );
                $subject->addButton(
                    'reject_review',
                    [
                        'label' => __('Refund'),
                        'onclick' => 'setLocation(\'' . $subject->getUrl('stripe_payments_admin/order/rejectReview') . '\')',
                        'class' => 'reject-review'
                    ]
                );
            }
        }

        return null;
    }
}