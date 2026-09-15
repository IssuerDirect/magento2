<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Sender;

class OrderSender
{
    private $subscriptionFlow;

    public function __construct(
        \StripeIntegration\Payments\Model\Subscription\Flow $subscriptionFlow
    ) {
        $this->subscriptionFlow = $subscriptionFlow;
    }

    /**
     * Will set flags which will be used to determine the email template to be used.
     * Created the plugin because it can be used when async emails are being sent as well as real-time emails.
     *
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $subject
     * @param $order
     * @return null
     */
    public function beforeSend(
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $subject,
        $order
    ) {
        if ($order->getPayment()->getAdditionalInformation("is_recurring_subscription")) {
            $this->subscriptionFlow->isRecurringOrderCreated = true;
        }

        if ($order->getPayment()->getAdditionalInformation("is_subscription_update")) {
            $this->subscriptionFlow->isSubscriptionChanged = true;
        }

        return null;
    }
}