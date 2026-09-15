<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Sender;

class CreditmemoSender
{
    private $subscriptionFlow;

    public function __construct(
        \StripeIntegration\Payments\Model\Subscription\Flow $subscriptionFlow
    ) {
        $this->subscriptionFlow = $subscriptionFlow;
    }

    /**
     * Will set a flag which will be used to determine the email template to be used.
     * Created the plugin because it can be used when async emails are being sent as well as real-time emails.
     *
     * @param \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $subject
     * @param $creditMemo
     * @return null
     */
    public function beforeSend(
        \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $subject,
        $creditMemo
    ) {
        if ($creditMemo->getOrder()->getPayment()->getAdditionalInformation("is_recurring_subscription")) {
            $this->subscriptionFlow->isSubscriptionRefunded = true;
        }

        return null;
    }
}