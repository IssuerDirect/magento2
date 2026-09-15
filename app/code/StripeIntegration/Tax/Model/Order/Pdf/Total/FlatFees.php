<?php

namespace StripeIntegration\Tax\Model\Order\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

class FlatFees extends DefaultTotal
{
    /**
     * @return bool
     */
    public function canDisplay()
    {
        return !empty($this->getFees());
    }

    /**
     * @return array
     */
    public function getTotalsForDisplay()
    {
        $fontSize = $this->getFontSize() ?: 7;
        $rows = [];

        foreach ($this->getFees() as $taxType => $feeData) {
            $amount = $feeData['amount'] ?? 0;
            $rows[] = [
                'amount' => $this->getOrder()->formatPriceTxt($amount),
                'label' => ucwords(str_replace('_', ' ', $taxType)) . ':',
                'font_size' => $fontSize,
            ];
        }

        return $rows;
    }

    private function getFees()
    {
        $feesJson = $this->getSource()->getData('stripe_flat_fees');
        if (!$feesJson) {
            return [];
        }

        $result = [];
        foreach (json_decode($feesJson, true) ?? [] as $taxType => $feeData) {
            if (($feeData['amount'] ?? 0) > 0) {
                $result[$taxType] = $feeData;
            }
        }

        return $result;
    }
}
