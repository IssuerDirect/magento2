<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Helper\Dispute;
use StripeIntegration\Payments\Helper\Radar;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class ChargeRefunded
{
    use StripeObjectTrait;

    private $creditmemoHelper;
    private $webhooksHelper;
    private $orderHelper;
    private $convert;
    private $helper;
    private $currencyHelper;
    private $config;
    private $refundModelFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\Stripe\RefundFactory $refundModelFactory
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->config = $config;
        $this->orderHelper = $orderHelper;
        $this->convert = $convert;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->webhooksHelper = $webhooksHelper;
        $this->helper = $helper;
        $this->currencyHelper = $currencyHelper;
        $this->refundModelFactory = $refundModelFactory;
    }

    public function process($arrEvent, $object)
    {
        if ($this->webhooksHelper->wasRefundedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.dispute.closed']);

        // Get the refund amount and currency
        $currentRefund = $this->getCurrentRefundFrom($object['id']);
        $currency = $currentRefund->getCurrency();
        $stripeRefundAmount = $currentRefund->getAmount();
        $formattedRefundAmount = $this->currencyHelper->formatStripePrice($stripeRefundAmount, $currency);

        // Record a refund transaction
        if (!empty($object['payment_intent']))
        {
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND;
            $paymentIntentId = $object['payment_intent'];
            $transaction = $this->helper->addTransaction($order, $paymentIntentId, $transactionType, $paymentIntentId);
            $transaction->setAdditionalInformation("amount", $formattedRefundAmount);
            $transaction->setAdditionalInformation("currency", $object['currency']);
            $transaction->save();
        }

        if ($order->getState() == "holded" && $order->canUnhold())
            $order->unhold();

        // If the Stripe currency does not match the order currency, do not create a credit memo
        if (strtolower($currentRefund->getCurrency()) != strtolower($order->getOrderCurrencyCode()))
        {
            $comment = __("A refund of %1 was issued via Stripe, but the currency is different than the order currency.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // Compare in Stripe amounts to avoid reverse conversion rounding issues
        $stripeOrderTotal = $this->convert->magentoAmountToStripeAmount($order->getGrandTotal(), $currency);
        if ($stripeOrderTotal != $stripeRefundAmount)
        {
            $comment = __("A refund of %1 was issued via Stripe, but the amount is different than the order amount.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // If an authorization is partially captured, we expect a payment_intent.succeeded webhook to arrive for the partial capture.
        $isPartialCapture = !$order->canCreditmemo() && $order->canInvoice() && $order->canCancel() && ($stripeRefundAmount < $stripeOrderTotal);
        if ($isPartialCapture)
        {
            return false;
        }

        // If the refund amount is larger than the order amount, this indicates a problem that should be reported to Stripe's customer support
        if ($stripeRefundAmount > $stripeOrderTotal)
        {
            $comment = __("A refund of %1 was issued via Stripe, but the amount is bigger than the order amount.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }
        else if ($stripeRefundAmount < $stripeOrderTotal)
        {
            $comment = __("A refund of %1 was issued via Stripe, but the amount is smaller than the order amount.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // If the order has at least one credit memo, do not create another one
        if ($order->hasCreditmemos())
        {
            $comment = __("A refund of %1 was issued via Stripe, but the order already has a credit memo.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // If the order has multiple invoices, do not create a credit memo
        if ($order->hasInvoices() && count($order->getInvoiceCollection()) > 1)
        {
            $comment = __("A refund of %1 was issued via Stripe, but the order has multiple invoices. Please manually refund the correct invoice offline.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // If the order has a single invoice which is open...
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        if ($invoice && $invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_OPEN)
        {
            $stripeInvoiceTotal = $this->convert->magentoAmountToStripeAmount($invoice->getGrandTotal(), $currency);
            if ($stripeInvoiceTotal == $stripeRefundAmount)
            {
                // If the invoice total matches the refund total, cancel the invoice and the order
                $comment = __("The payment of %1 was canceled via Stripe.", $formattedRefundAmount);
                $this->helper->cancelOrCloseOrder($order, $comment);
                return true;
            }
            else
            {
                $comment = __("A refund of %1 was issued via Stripe, but the invoice amount is different than the refund amount.", $formattedRefundAmount);
                $this->orderHelper->addOrderComment($comment, $order);
                $this->orderHelper->saveOrder($order);
                return false;
            }
        }

        // If the order has a single invoice which is canceled
        if ($invoice && $invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_CANCELED)
        {
            $comment = __("A refund of %1 was issued via Stripe, but the invoice is already canceled.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        // Because more cases are handled here, only change the status if the order is disputed or if it was set to
        // manual review
        $orderWasDisputed = ($order->getState() === Dispute::STRIPE_DISPUTE_STATE_CODE);
        if ($order->getState() === Dispute::STRIPE_DISPUTE_STATE_CODE || $order->getState() === Radar::MANUAL_REVIEW_STATE_CODE) {
            $this->orderHelper->restoreOrderState($order);
        }

        // A full refund has been issued
        if ($order->canCancel())
        {
            $comment = __("The payment of %1 was canceled via Stripe.", $formattedRefundAmount);
            $this->helper->cancelOrCloseOrder($order, $comment);
            return true;
        }
        else if ($order->canCreditmemo())
        {
            if ($invoice)
            {
                if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID)
                {
                    if ($orderWasDisputed) {
                        $comment = __("The dispute was resolved with a refund of %1 via Stripe.", $formattedRefundAmount);
                        $order->addStatusToHistory($order->getStatus(), $comment);
                    }
                    // Refund the invoice offline
                    if ($currentRefund->isPending())
                    {
                        $creditmemo = $this->creditmemoHelper->createPendingCreditmemoForInvoice($invoice, $order);
                        $comment = __("A refund of %1 is pending via Stripe. The credit memo will be updated when the refund is confirmed.", $formattedRefundAmount);
                    }
                    else
                    {
                        $creditmemo = $this->creditmemoHelper->createOfflineCreditmemoForInvoice($invoice, $order);
                        $comment = __("We refunded %1 through Stripe.", $formattedRefundAmount);
                    }
                    $order->addStatusToHistory($order->getStatus(), $comment);
                    $this->orderHelper->saveOrder($order);
                    return true;
                }
                else
                {
                    // This should never hit due to prior invoice status checks
                    $comment = __("A refund of %1 was issued via Stripe, but the invoice is not paid.", $formattedRefundAmount);
                    $this->orderHelper->addOrderComment($comment, $order);
                    $this->orderHelper->saveOrder($order);
                    return false;
                }
            }
            else
            {
                $comment = __("A refund of %1 was issued via Stripe, but the order has not yet been invoiced.", $formattedRefundAmount);
                $this->orderHelper->addOrderComment($comment, $order);
                $this->orderHelper->saveOrder($order);
                return false;
            }
        }
        else if (!$order->canCreditmemo())
        {
            // Unknown case which should never hit
            $comment = __("A refund of %1 was issued via Stripe, but a Credit Memo could not be created.", $formattedRefundAmount);
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            return false;
        }

        return true;
    }

    private function getCurrentRefundFrom($chargeId)
    {
        $lastRefundDate = 0;
        $currentRefund = null;

        $refunds = $this->config->getStripeClient()->refunds->all(['charge' => $chargeId]);
        foreach ($refunds->data as $refund)
        {
            $refundModel = $this->refundModelFactory->create()->fromObject($refund);
            // There might be multiple refunds, and we are looking for the most recent one
            if ($refundModel->getCreatedTimestamp() > $lastRefundDate)
            {
                $lastRefundDate = $refundModel->getCreatedTimestamp();
                $currentRefund = $refundModel;
            }
        }

        return $currentRefund;
    }
}