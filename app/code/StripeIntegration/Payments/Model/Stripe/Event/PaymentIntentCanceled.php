<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Helper\Radar;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class PaymentIntentCanceled
{
    use StripeObjectTrait;

    private $webhooksHelper;
    private $helper;
    private $orderHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
    }

    public function process($arrEvent, $object)
    {
        if ($object["status"] != "canceled")
            return;

        if ($this->webhooksHelper->wasReviewedFromAdmin($object)) {
            return;
        }

        $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

        foreach ($orders as $order) {
            $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.refunded', 'review.closed']);
        }

        foreach ($orders as $order)
        {
            $transactionId = $this->orderHelper->getTransactionId($order);
            if ($transactionId && $transactionId != $object["id"])
            {
                continue;
            }

            // Failsafe if the order is not cancelled in the review.closed event
            if ($order->getState() === Radar::MANUAL_REVIEW_STATE_CODE) {
                $this->orderHelper->restoreOrderState($order);
                $comment = $this->orderHelper->getCancelledComment($order, "a Stripe Dashboard admin user", $object["cancellation_reason"]);
                $this->helper->cancelOrCloseOrder($order, $comment);
                continue;
            }

            $paymentMethod = $order->getPayment()->getMethod();
            $cancelOrder = true;
            if ($object["cancellation_reason"] == "abandoned")
            {
                $msg = __("Customer abandoned the cart. The payment session has expired.");

                // If the payment method is Bank transfers, then set the message appropriately,
                // as this will be canceled via cron
                if ($paymentMethod == 'stripe_payments_bank_transfers') {
                    $msg = __("The payment was cancelled because it was overdue.");
                }
            }
            else if ($this->authorizationExpired($object))
            {
                $msg = __("The payment authorization has expired.");
                $cancelOrder = false;
            }
            else if ($object["cancellation_reason"] == "duplicate")
            {
                $msg = __("The payment was canceled because it was a duplicate of another payment.");
            }
            else if ($object["cancellation_reason"] == "requested_by_customer")
            {
                $msg = __("The payment was canceled at the request of the customer.");
            }
            else if (!empty($object["cancellation_reason"]))
            {
                $readable = str_replace("_", " ", $object["cancellation_reason"]);
                $msg = __("The payment was canceled: %1", ucwords($readable));
            }
            else
            {
                $msg = __("The payment was canceled.");
            }

            if ($cancelOrder && $object["amount_received"] == 0 && $order->getTotalDue() == $order->getGrandTotal())
            {
                $order->getPayment()->setCancelOfflineWithComment($msg);
                $this->helper->cancelOrCloseOrder($order, $msg, true, true);
            }
            else
            {
                $this->webhooksHelper->addOrderComment($order, $msg);
            }
        }
    }

    private function authorizationExpired($object)
    {
        if (!$this->isManualCapture($object))
            return false;

        return ($object["cancellation_reason"] == "expired" || $object["cancellation_reason"] == "automatic");
    }

    private function isManualCapture($object)
    {
        // Redirect flow
        if (!empty($object["capture_method"]) && $object["capture_method"] == "manual")
            return true;

        // Embedded flow
        if (!empty($object["payment_method_options"]) && is_array($object["payment_method_options"]))
        {
            foreach ($object["payment_method_options"] as $options)
            {
                if (!empty($options["capture_method"]) && $options["capture_method"] == "manual")
                    return true;
            }
        }

        return false;
    }
}