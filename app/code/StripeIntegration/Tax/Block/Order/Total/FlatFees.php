<?php

namespace StripeIntegration\Tax\Block\Order\Total;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class FlatFees extends Template
{
    /**
     * Reads stripe_flat_fees and registers one total row per fee type.
     * Works for order, invoice, creditmemo and quote (admin create order) sources.
     */
    public function initTotals()
    {
        /** @var Totals $parent */
        $parent = $this->getParentBlock();
        $source = $parent->getSource();

        $feesJson = $this->getFeesJson($source);
        if (!$feesJson) {
            return $this;
        }

        $after = $this->getAfter($parent);
        $fees = json_decode($feesJson, true) ?? [];

        foreach ($fees as $taxType => $feeData) {
            $amount = $feeData['amount'] ?? 0;
            if ($amount <= 0) {
                continue;
            }

            $parent->addTotal(
                new DataObject([
                    'code' => 'stripe_flat_fee_' . $taxType,
                    'strong' => false,
                    'value' => $amount,
                    'base_value' => $feeData['base_amount'] ?? $amount,
                    'label' => __(ucwords(str_replace('_', ' ', $taxType))),
                ]),
                $after
            );
        }

        return $this;
    }

    /**
     * Resolves the fees JSON from the source object.
     * For invoices and credit memos only the source itself is checked: falling back to the
     * parent order would cause the fee to appear on entities that were deliberately created
     * without it (e.g. a second invoice after the fee was already charged on the first).
     * For orders and quotes the fallback chain continues:
     * 1. The source itself.
     * 2. The parent order, for unsaved invoice or creditmemo on creation forms (legacy path).
     * 3. The shipping address, for admin create-order where the source is a quote.
     */
    private function getFeesJson($source)
    {
        if ($feesJson = $source->getData('stripe_flat_fees')) {
            return $feesJson;
        }

        if ($source instanceof Invoice || $source instanceof Creditmemo) {
            return null;
        }

        if ($order = $source->getOrder()) {
            if ($feesJson = $order->getData('stripe_flat_fees')) {
                return $feesJson;
            }
        }

        if ($shippingAddress = $source->getShippingAddress()) {
            if ($feesJson = $shippingAddress->getData('stripe_flat_fees')) {
                return $feesJson;
            }
        }

        return null;
    }

    private function getAfter($parent)
    {
        if ($parent->getTotal('shipping_incl')) {
            return 'shipping_incl';
        } elseif ($parent->getTotal('shipping')) {
            return 'shipping';
        } elseif ($parent->getTotal('subtotal_incl')) {
            return 'subtotal_incl';
        }

        return 'subtotal';
    }
}
