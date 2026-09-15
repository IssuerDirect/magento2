<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;
use StripeIntegration\Payments\Exception\RetryLaterException;

class ReviewClosed
{
    use StripeObjectTrait;

    private $eventManager;
    private $webhooksHelper;
    private $helper;
    private $orderHelper;
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->eventManager = $eventManager;
        $this->webhooksHelper = $webhooksHelper;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->config = $config;

    }
    public function process($arrEvent, $object)
    {
        if ($this->webhooksHelper->wasReviewedFromAdmin($object)) {
            return;
        }

        if (empty($object['payment_intent']))
            return;

        $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

        foreach ($orders as $order)
        {
            $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.refunded']);
        }

        foreach ($orders as $order)
        {
            $this->eventManager->dispatch(
                'stripe_payments_review_closed_before',
                ['order' => $order, 'object' => $object]
            );

            if ($object['reason'] == "approved") {
                $this->orderHelper->setAsApproved($order, __("a Stripe Dashboard admin user"));
            } else if ($object['reason'] == "canceled") {
                // Case for manually canceling uncaptured payments; the order will be closed
                $this->orderHelper->restoreOrderState($order);
                $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($object['payment_intent'], []);
                if (isset($paymentIntent->cancellation_reason)) {
                    $comment = $this->orderHelper->getCancelledComment($order, "a Stripe Dashboard admin user", $paymentIntent->cancellation_reason);
                } else {
                    $comment = $this->orderHelper->getCancelledComment($order, "a Stripe Dashboard admin user");
                }
                $this->helper->cancelOrCloseOrder($order, $comment);
            } else {
                // We throw a RetryLaterException because we depend on the charge.refunded event to create a Credit memo first
                $this->checkOrderRefunded($order);
                $this->orderHelper->setAsRejected($order, "a Stripe Dashboard admin user", $object['reason']);
            }

            $this->eventManager->dispatch(
                'stripe_payments_review_closed_after',
                ['order' => $order, 'object' => $object]
            );
        }
    }

    private function checkOrderRefunded($order)
    {
        if ($order->getTotalRefunded() == 0) {
            throw new RetryLaterException("The order does not have a credit memo, so status cannot change. Try again shortly.");
        }
    }
}