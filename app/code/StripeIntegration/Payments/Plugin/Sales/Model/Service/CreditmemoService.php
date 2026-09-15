<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Service;

class CreditmemoService
{
    private $refundsHelper;
    private $creditmemoRepository;
    private $helper;
    private $orderHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper
    )
    {
        $this->refundsHelper = $refundsHelper;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
    }

    public function beforeRefund(
        \Magento\Sales\Model\Service\CreditmemoService $subject,
        \Magento\Sales\Api\Data\CreditmemoInterface $creditmemo,
        $offlineRequested = false
    )
    {
        if (!$offlineRequested)
        {
            return null;
        }

        $order = $creditmemo->getOrder();
        if (!$order || !$order->getPayment())
        {
            return null;
        }

        $payment = $order->getPayment();
        if (strpos($payment->getMethod(), 'stripe_') !== 0)
        {
            return null;
        }

        // When finalizing a pending credit memo (STATE_OPEN → STATE_REFUNDED), ensure order
        // totals are correct before Magento's validateForRefund() runs.
        if ($creditmemo->getState() == \Magento\Sales\Model\Order\Creditmemo::STATE_OPEN)
        {
            $this->correctOrderRefundedTotals($order);
        }

        return null;
    }

    public function afterRefund(
        \Magento\Sales\Model\Service\CreditmemoService $subject,
        \Magento\Sales\Api\Data\CreditmemoInterface $result,
        \Magento\Sales\Api\Data\CreditmemoInterface $creditmemo,
        $offlineRequested = false
    )
    {
        if ($offlineRequested)
        {
            return $result;
        }

        $order = $creditmemo->getOrder();
        if (!$order || !$order->getPayment())
        {
            return $result;
        }

        $payment = $order->getPayment();
        if (strpos($payment->getMethod(), 'stripe_') !== 0)
        {
            return $result;
        }

        if ($this->refundsHelper->isLastRefundPending())
        {
            // Save the credit memo in pending (STATE_OPEN) status without triggering Magento's refund flow.
            // Since we bypass Magento's refund flow, we must ensure order totals are correct.
            $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN);
            $this->creditmemoRepository->save($creditmemo);
            $this->correctOrderRefundedTotals($order);

            $comment = __("The refund is being processed by Stripe and is currently in a pending state. The credit memo will be updated automatically once the refund is confirmed.");
            $this->orderHelper->addOrderComment($comment, $order);
            $this->orderHelper->saveOrder($order);
            $this->refundsHelper->resetLastRefundPending();
            $this->helper->addWarning($comment);
        }

        return $result;
    }

    /**
     * Recalculate order refund totals from finalized credit memos only.
     * Pending (STATE_OPEN) credit memos are excluded since the refund has not been confirmed yet.
     */
    private function correctOrderRefundedTotals($order)
    {
        $baseTotalRefunded = 0;
        $totalRefunded = 0;

        // Initialize item-level refund accumulators keyed by order item ID
        $itemRefunds = [];
        foreach ($order->getAllItems() as $orderItem)
        {
            $itemRefunds[$orderItem->getItemId()] = [
                'qty' => 0,
                'tax' => 0,
                'base_tax' => 0,
                'discount_tax_compensation' => 0,
                'base_discount_tax_compensation' => 0,
                'amount' => 0,
                'base_amount' => 0,
                'discount' => 0,
                'base_discount' => 0,
            ];
        }

        $creditmemosCollection = $order->getCreditmemosCollection();
        if ($creditmemosCollection)
        {
            // Resets the collection so that the next time it is iterated, Magento will reload the credit memo data fresh
            // from the database rather than using the in-memory cached version.
            $creditmemosCollection->clear();
        }

        foreach ($creditmemosCollection as $creditmemo)
        {
            if ($creditmemo->getState() == \Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED)
            {
                $baseTotalRefunded += $creditmemo->getBaseGrandTotal();
                $totalRefunded += $creditmemo->getGrandTotal();

                foreach ($creditmemo->getAllItems() as $creditmemoItem)
                {
                    $orderItemId = $creditmemoItem->getOrderItemId();
                    if (isset($itemRefunds[$orderItemId]))
                    {
                        $itemRefunds[$orderItemId]['qty'] += $creditmemoItem->getQty();
                        $itemRefunds[$orderItemId]['tax'] += $creditmemoItem->getTaxAmount();
                        $itemRefunds[$orderItemId]['base_tax'] += $creditmemoItem->getBaseTaxAmount();
                        $itemRefunds[$orderItemId]['discount_tax_compensation'] += $creditmemoItem->getDiscountTaxCompensationAmount();
                        $itemRefunds[$orderItemId]['base_discount_tax_compensation'] += $creditmemoItem->getBaseDiscountTaxCompensationAmount();
                        $itemRefunds[$orderItemId]['amount'] += $creditmemoItem->getRowTotal();
                        $itemRefunds[$orderItemId]['base_amount'] += $creditmemoItem->getBaseRowTotal();
                        $itemRefunds[$orderItemId]['discount'] += $creditmemoItem->getDiscountAmount();
                        $itemRefunds[$orderItemId]['base_discount'] += $creditmemoItem->getBaseDiscountAmount();
                    }
                }
            }
        }

        $order->setBaseTotalRefunded($baseTotalRefunded);
        $order->setTotalRefunded($totalRefunded);

        // Correct item-level refund totals to match only finalized credit memos
        foreach ($order->getAllItems() as $orderItem)
        {
            $itemId = $orderItem->getItemId();
            if (isset($itemRefunds[$itemId]))
            {
                $orderItem->setQtyRefunded($itemRefunds[$itemId]['qty']);
                $orderItem->setTaxRefunded($itemRefunds[$itemId]['tax']);
                $orderItem->setBaseTaxRefunded($itemRefunds[$itemId]['base_tax']);
                $orderItem->setDiscountTaxCompensationRefunded($itemRefunds[$itemId]['discount_tax_compensation']);
                $orderItem->setBaseDiscountTaxCompensationRefunded($itemRefunds[$itemId]['base_discount_tax_compensation']);
                $orderItem->setAmountRefunded($itemRefunds[$itemId]['amount']);
                $orderItem->setBaseAmountRefunded($itemRefunds[$itemId]['base_amount']);
                $orderItem->setDiscountRefunded($itemRefunds[$itemId]['discount']);
                $orderItem->setBaseDiscountRefunded($itemRefunds[$itemId]['base_discount']);
            }
        }
    }
}
