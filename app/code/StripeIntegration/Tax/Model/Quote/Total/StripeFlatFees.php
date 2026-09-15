<?php

namespace StripeIntegration\Tax\Model\Quote\Total;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use StripeIntegration\Tax\Helper\Currency;
use StripeIntegration\Tax\Model\StripeTax;

/**
 * Quote total collector for all order-level flat-amount fees returned by Stripe.
 *
 * Stripe can include flat-amount entries in the calculation-level tax_breakdown
 * (e.g. retail_delivery_fee for CO and MN). These do not appear in line item
 * tax breakdowns. This collector handles all such entries generically so that no
 * new collector is needed when new fee types are introduced by Stripe.
 *
 * All collected fees are stored as a JSON blob in the stripe_flat_fees column,
 * which travels through quote_address → sales_order → sales_invoice.
 */
class StripeFlatFees extends AbstractTotal
{
    public const CODE = 'stripe_flat_fees';

    private $stripeTax;
    private $currencyHelper;

    public function __construct(
        StripeTax $stripeTax,
        Currency $currencyHelper
    ) {
        $this->stripeTax = $stripeTax;
        $this->currencyHelper = $currencyHelper;
        $this->setCode(self::CODE);
    }

    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        parent::collect($quote, $shippingAssignment, $total);

        $address = $shippingAssignment->getShipping()->getAddress();
        $address->setData(self::CODE, null);

        if (!$this->stripeTax->isEnabled()
            || !$shippingAssignment->getItems()
            || !$this->stripeTax->hasValidResponse()
        ) {
            return $this;
        }

        $fees = $this->stripeTax->getResponse()->getFlatAmountFees();

        if (empty($fees)) {
            return $this;
        }

        $baseToQuoteRate = $quote->getBaseToQuoteRate() ?: 1.0;
        $totalFee = 0.0;
        $totalBaseFee = 0.0;
        $feesForStorage = [];

        foreach ($fees as $taxType => $feeData) {
            $amount = $this->currencyHelper->stripeAmountToMagentoAmount(
                $feeData['stripe_amount'],
                $feeData['currency']
            );
            $baseAmount = round($amount / $baseToQuoteRate, 4);

            $totalFee += $amount;
            $totalBaseFee += $baseAmount;

            $feesForStorage[$taxType] = [
                'amount' => $amount,
                'base_amount' => $baseAmount,
            ];
        }

        $total->addTotalAmount($this->getCode(), $totalFee);
        $total->addBaseTotalAmount($this->getCode(), $totalBaseFee);
        $address->setData(self::CODE, json_encode($feesForStorage));

        return $this;
    }

    public function fetch(Quote $quote, Total $total)
    {
        if (!$this->stripeTax->isEnabled() || !$this->stripeTax->hasValidResponse()) {
            return null;
        }

        $fees = $this->stripeTax->getResponse()->getFlatAmountFees();

        if (empty($fees)) {
            return null;
        }

        $rows = [];
        foreach ($fees as $taxType => $feeData) {
            $amount = $this->currencyHelper->stripeAmountToMagentoAmount(
                $feeData['stripe_amount'],
                $feeData['currency']
            );
            if ($amount > 0) {
                $rows[] = [
                    'code' => self::CODE . '_' . $taxType,
                    'title' => __(ucwords(str_replace('_', ' ', $taxType))),
                    'value' => $amount,
                ];
            }
        }

        return $rows ?: null;
    }

    public function getLabel()
    {
        return __('Flat Fees');
    }
}
