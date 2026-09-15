<?php

namespace StripeIntegration\Tax\Plugin\Sales\Model\Order\Creditmemo\Total;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\Tax as CreditmemoTax;
use StripeIntegration\Tax\Model\StripeTax;

class Tax
{
    private $stripeTax;

    public function __construct(
        StripeTax $stripeTax
    ) {
        $this->stripeTax = $stripeTax;
    }

    public function afterCollect(CreditmemoTax $subject, $result, Creditmemo $creditmemo)
    {
        if (!$this->stripeTax->isEnabled()) {
            return $result;
        }

        $this->addFlatFeesToCreditmemo($creditmemo);

        return $result;
    }

    /**
     * Reads the stripe_flat_fees stored on the parent invoice and adds the total to the
     * credit memo grand total. The full fee is refunded since it is an order-level flat charge.
     * When the credit memo is created offline directly from the order page it has no linked
     * invoice, so we scan all invoices on the order to find the one that carries the fee.
     * If a previous credit memo on the same order already has the fee set, it is not applied
     * again to avoid double-refunding.
     */
    private function addFlatFeesToCreditmemo(Creditmemo $creditmemo)
    {
        // Don't allow the fee to be refunded more than once across multiple credit memos
        foreach ($creditmemo->getOrder()->getCreditmemosCollection() as $existingCreditmemo) {
            if ($existingCreditmemo->getData('stripe_flat_fees')) {
                return;
            }
        }

        $feesJson = null;
        $invoice = $creditmemo->getInvoice();

        if ($invoice) {
            $feesJson = $invoice->getData('stripe_flat_fees');
        }

        // take into account that the invoice might not be available, for when an offline credit memo is created
        if (!$feesJson) {
            foreach ($creditmemo->getOrder()->getInvoiceCollection() as $existingInvoice) {
                if ($existingInvoice->getData('stripe_flat_fees')) {
                    $feesJson = $existingInvoice->getData('stripe_flat_fees');
                    break;
                }
            }
        }

        if (!$feesJson) {
            return;
        }

        $fees = json_decode($feesJson, true) ?? [];
        $totalFee = 0.0;
        $totalBaseFee = 0.0;

        foreach ($fees as $feeData) {
            $totalFee += $feeData['amount'] ?? 0;
            $totalBaseFee += $feeData['base_amount'] ?? 0;
        }

        if ($totalFee <= 0) {
            return;
        }

        $creditmemo->setData('stripe_flat_fees', $feesJson);
        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $totalFee);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $totalBaseFee);
    }
}
