<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class RefundFailed
{
    use StripeObjectTrait;

    private $webhooksHelper;
    private $orderHelper;
    private $currencyHelper;
    private $refundModelFactory;
    private $emailHelper;
    private $creditmemoHelper;
    private $refundsHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Email $emailHelper,
        \StripeIntegration\Payments\Model\Stripe\RefundFactory $refundModelFactory,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->orderHelper = $orderHelper;
        $this->webhooksHelper = $webhooksHelper;
        $this->currencyHelper = $currencyHelper;
        $this->emailHelper = $emailHelper;
        $this->refundModelFactory = $refundModelFactory;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->refundsHelper = $refundsHelper;
    }

    public function process($arrEvent, $object)
    {
        $refundModel = $this->refundModelFactory->create()->fromRefundId($object['id']);

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        // The refund will not be confirmed; remove the pending-refund marker, if any.
        $this->refundsHelper->removePendingRefundMarker($object['id']);

        $currency = $refundModel->getCurrency();
        $formattedAmount = $this->currencyHelper->formatStripePrice($refundModel->getAmount(), $currency);

        $comment = __("A refund of %1 has failed.", $formattedAmount);
        $this->orderHelper->addOrderComment($comment, $order);

        $this->cancelPendingCreditmemos($order);

        $this->orderHelper->saveOrder($order);

        $this->sendRefundFailedEmail($arrEvent, $order, $formattedAmount);
    }

    private function cancelPendingCreditmemos($order)
    {
        foreach ($order->getCreditmemosCollection() as $creditmemo)
        {
            if ($creditmemo->getState() == \Magento\Sales\Model\Order\Creditmemo::STATE_OPEN)
            {
                $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_CANCELED);
                $this->creditmemoHelper->saveCreditmemo($creditmemo);
            }
        }
    }

    private function sendRefundFailedEmail($arrEvent, $order, $formattedAmount)
    {
        try
        {
            $generalName = $this->emailHelper->getName('general');
            $generalEmail = $this->emailHelper->getEmail('general');

            if ($arrEvent['livemode'])
                $mode = '';
            else
                $mode = 'test/';

            $refundId = $arrEvent['data']['object']['id'];

            $templateVars = [
                'formattedAmount' => $formattedAmount,
                'orderIncrementId' => $order->getIncrementId(),
                'refundLink' => "https://dashboard.stripe.com/{$mode}refunds/" . $refundId
            ];

            $this->emailHelper->send('stripe_refund_failed', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);
        }
        catch (\Exception $e)
        {
            // Silently fail - the order comment was already added
        }
    }
}
