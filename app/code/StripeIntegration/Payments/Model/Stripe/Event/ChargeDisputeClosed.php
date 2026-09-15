<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Helper\Dispute;
use StripeIntegration\Payments\Helper\Webhooks;
use StripeIntegration\Payments\Helper\Order;
use StripeIntegration\Payments\Model\Config;
use StripeIntegration\Payments\Helper\Convert;
use StripeIntegration\Payments\Helper\Currency;
use StripeIntegration\Payments\Helper\Creditmemo;
use StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class ChargeDisputeClosed
{
    use StripeObjectTrait;

    private $webhooksHelper;
    private $orderHelper;
    private $disputeHelper;
    private $config;
    private $convertHelper;
    private $currencyHelper;
    private $creditmemoHelper;

    public function __construct(
        StripeObjectServicePool $stripeObjectServicePool,
        Webhooks $webhooksHelper,
        Order $orderHelper,
        Dispute $disputeHelper,
        Config $config,
        Convert $convertHelper,
        Currency $currencyHelper,
        Creditmemo $creditmemoHelper
    ) {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->orderHelper = $orderHelper;
        $this->disputeHelper = $disputeHelper;
        $this->config = $config;
        $this->convertHelper = $convertHelper;
        $this->currencyHelper = $currencyHelper;
        $this->creditmemoHelper = $creditmemoHelper;
    }

    /**
     * Handles the closing of a dispute:
     *      -if the dispute is an inquiry:
     *          - if the inquiry is closed without any further action, order is reset to the status before it
     *          was put in dispute
     *          - if the funds are refunded, the refund is handled by the charge_refunded event, order is set to the
     *          status before it was disputed and offline credit memo is created
     *      - if the dispute is a chargeback or a compliance dispute:
     *          - if the dispute is lost, the order is set to the status before disputed and then an offline credit memo
     *          is created
     *          - if the dispute is won, the order is set to the status before dispute and a comment is added to it
     * @param $arrEvent
     * @param $object
     * @return bool|void
     * @throws \StripeIntegration\Payments\Exception\MissingOrderException
     * @throws \StripeIntegration\Payments\Exception\WebhookException
     */
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.refunded']);

        $this->orderHelper->restoreOrderState($order);

        if ($this->disputeHelper->isDisputeInquiry($object)) {
            if ($this->disputeHelper->isInquiryClosed($object)) {
                $charge = $this->config->getStripeClient()->charges->retrieve($object['charge']);
                if (!$this->disputeHelper->isChargeRefunded($charge, $object)) {
                    $comment = __('The issuing bank chose not to pursue this dispute. No funds or fees were withdrawn from your account. The order will be returned to the status it had before it was disputed.');
                    $order->addStatusToHistory($order->getStatus(), $comment, false);
                    $this->orderHelper->saveOrder($order);
                }
            }
        }

        if ($this->disputeHelper->isDisputeLost($object)) {
            $currency = $object['currency'];
            $disputeStripeAmount = (int)$object['amount'];
            $formattedDisputeAmount = $this->currencyHelper->formatStripePrice($disputeStripeAmount, $currency);

            // If the Stripe currency does not match the order currency, do not create a credit memo
            if (strtolower($currency) != strtolower($order->getOrderCurrencyCode())) {
                $comment = __("The amount of %1 was withdrawn via Stripe, but the currency is different than the order currency.", $formattedDisputeAmount);
                $this->orderHelper->addOrderComment($comment, $order);
                $this->orderHelper->saveOrder($order);

                return false;
            }

            // If the order has at least one credit memo, do not create another one
            if ($order->hasCreditmemos())
            {
                $comment = __("A dispute of %1 was lost, but the order already has a credit memo. To better reflect the transactions in Stripe, manual offline credit memos can be created for the order.", $formattedDisputeAmount);
                $this->orderHelper->addOrderComment($comment, $order);
                $this->orderHelper->saveOrder($order);

                return false;
            }

            $invoices = $order->getInvoiceCollection()->getItems();
            if ($order->canCreditmemo() &&
                !empty($invoices)
            ) {
                // If there is only one invoice and the dispute amount equals the remaining paid amount,
                // compare in Stripe amounts to avoid reverse conversion rounding
                $totalPaidStripeAmount = $this->convertHelper->magentoAmountToStripeAmount($order->getTotalPaid(), $currency);
                // Get the amounts that were canceled or refunded
                $totalCanceled = $this->convertHelper->magentoAmountToStripeAmount($order->getTotalCanceled(), $currency);
                $totalRefunded = $this->convertHelper->magentoAmountToStripeAmount($order->getTotalRefunded(), $currency);
                // Get the remaining amount to be refunded
                $totalRemainingStripeAmount = $totalPaidStripeAmount - $totalCanceled - $totalRefunded;
                if (count($invoices) == 1 && $totalRemainingStripeAmount == $disputeStripeAmount) {
                    $comment = __("The dispute was resolved with a refund of %1 via Stripe.", $formattedDisputeAmount);
                    $order->addStatusToHistory($order->getStatus(), $comment);
                    $invoice = array_pop($invoices);
                    if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID) {
                        $creditmemo = $this->creditmemoHelper->createOfflineCreditmemoForInvoice($invoice, $order);
                        $this->creditmemoHelper->saveCreditmemo($creditmemo);
                    }
                } else {
                    $comment = __("A dispute of %1 was lost. To better reflect the transactions in Stripe, manual offline credit memos can be created for the order.", $formattedDisputeAmount);
                    $order->addStatusToHistory($order->getStatus(), $comment);
                }

                $this->orderHelper->saveOrder($order);

                return true;
            }
        } elseif ($this->disputeHelper->isDisputeWon($object)) {
            $comment = __('The issuing bank resolved this dispute in your favour. The order will be returned to the status it had before it was disputed.');
            $order->addStatusToHistory($order->getStatus(), $comment, false);
            if ($this->disputeHelper->hasReinstatedAmount($object)) {
                $currency = $object['currency'];
                $fullReinstatedStripeAmount = $this->disputeHelper->getFullReinstatedAmount($object);
                $comment = __('The disputed amount of %1 has been returned to you.',
                    $this->currencyHelper->formatStripePrice($fullReinstatedStripeAmount, $currency)
                );
                $this->orderHelper->addOrderComment($comment, $order);
            }
            $this->orderHelper->saveOrder($order);

            return true;
        }
    }
}