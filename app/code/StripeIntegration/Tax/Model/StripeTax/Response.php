<?php

namespace StripeIntegration\Tax\Model\StripeTax;

use StripeIntegration\Tax\Helper\LineItems;
use StripeIntegration\Tax\Helper\Tax;
use Magento\Framework\Serialize\SerializerInterface;

class Response
{
    private $data;
    private $lineItemsHelper;
    private $serializer;

    public function __construct(
        LineItems $lineItemsHelper,
        SerializerInterface $serializer
    ) {
        $this->lineItemsHelper = $lineItemsHelper;
        $this->serializer = $serializer;
    }

    public function setData($data)
    {
        if (is_string($data)) {
            $data = $this->serializer->unserialize($data);
        }

        $this->data = $data;

        return $this;
    }

    public function clear()
    {
        $this->data = [];
    }

    public function getLineItemData($id)
    {
        $lineItems = $this->data['line_items']['data'];
        $lineItem = $this->lineItemsHelper->getLineItemByReference($id, $lineItems);
        if ($lineItem) {
            $excludeTaxTypes = array_keys($this->getFlatAmountFees());
            $lineItemDetails = $this->getItemDetailsForCalculation($lineItem, $excludeTaxTypes);
            $lineItemDetails['stripe_currency'] = strtoupper($this->data['currency']);
        } else {
            $lineItemDetails = $this->getDataForZeroTax($id);
        }

        return $lineItemDetails;
    }

    public function getShippingCostData()
    {
        $shippingCost = $this->data['shipping_cost'];
        $excludeTaxTypes = array_keys($this->getFlatAmountFees());
        $shippingCostDetails = $this->getItemDetailsForCalculation($shippingCost, $excludeTaxTypes);
        $shippingCostDetails['stripe_currency'] = strtoupper($this->data['currency']);

        return $shippingCostDetails;
    }

    public function getDataForZeroTax($id = null)
    {
        return [
            'stripe_total_calculated_amount' => $id ? $this->data['prices'][$id] : $this->data['shipping'],
            'stripe_total_calculated_tax' => 0,
            'stripe_applied_taxes' => [],
            'stripe_currency' => $this->data['currency']
        ];
    }

    public function getTaxCalculationId()
    {
        return $this->data['id'];
    }

    /**
     * Returns all flat-amount fee entries from the calculation-level tax_breakdown,
     * keyed by tax_type (e.g. 'retail_delivery_fee'). Only entries where rate_type
     * is 'flat_amount' and the statutory fee amount is greater than zero are included.
     *
     * @return array<string, array{stripe_amount: int, currency: string, state: string|null}>
     */
    public function getFlatAmountFees()
    {
        if (empty($this->data['tax_breakdown'])) {
            return [];
        }

        $fees = [];
        foreach ($this->data['tax_breakdown'] as $taxItem) {
            $rateType = $taxItem['tax_rate_details']['rate_type'] ?? null;
            $flatAmount = $taxItem['tax_rate_details']['flat_amount'] ?? null;
            $taxabilityReason = $taxItem['taxability_reason'] ?? null;

            // Don't process the item if the rate type is not flat_amount, if the flat amount is invalid
            // or if the user is not collecting that type of tax
            if ($rateType !== 'flat_amount'
                || empty($flatAmount)
                || $flatAmount['amount'] <= 0
                || $taxabilityReason === 'not_collecting'
            ) {
                continue;
            }

            $taxType = $taxItem['tax_rate_details']['tax_type'] ?? 'flat_fee';
            $fees[$taxType] = [
                'stripe_amount' => $flatAmount['amount'],
                'currency' => $flatAmount['currency'],
                'state' => $taxItem['tax_rate_details']['state'] ?? null,
            ];
        }

        return $fees;
    }

    private function getItemDetailsForCalculation($lineItem, array $excludeTaxTypes = [])
    {
        // Exclude flat fees from the breakdown
        $filteredBreakdown = [];
        foreach ($lineItem['tax_breakdown'] as $entry) {
            $taxType = $entry['tax_rate_details']['tax_type'] ?? null;
            if (!in_array($taxType, $excludeTaxTypes, true)) {
                $filteredBreakdown[] = $entry;
            }
        }

        // Calculate the total tax
        $totalTax = 0;
        foreach ($filteredBreakdown as $entry) {
            $totalTax += $entry['amount'];
        }

        return [
            'stripe_total_calculated_amount' => $lineItem['amount'],
            'stripe_total_calculated_tax' => $totalTax,
            'price_includes_tax' => $lineItem['tax_behavior'] == Tax::TAX_BEHAVIOR_INCLUSIVE,
            'stripe_applied_taxes' => $this->getAppliedTaxes($filteredBreakdown),
        ];
    }

    private function getAppliedTaxes($taxBreakdown)
    {
        $appliedTaxes = [];

        foreach ($taxBreakdown as $key => $taxItem) {
            if ($taxItem['amount'] == 0) {
                continue;
            }
            $taxArray = [
                'code' => $this->getTaxCode($key, $taxItem),
                'percent' => (float)$taxItem['tax_rate_details']['percentage_decimal'],
                'title' => $this->getTaxTitle($taxItem),
                'amount' => $taxItem['amount']
            ];
            $appliedTaxes[$taxItem['jurisdiction']['level']][] = $taxArray;
        }

        return $appliedTaxes;
    }

    private function getTaxCode($key, $taxItem)
    {
        $code = sprintf('%s - %s - ', $taxItem['jurisdiction']['country'], $taxItem['jurisdiction']['level']);
        if (($taxItem['jurisdiction']['state']) !== null) {
            $code .= $taxItem['jurisdiction']['state'] . ' - ';
        }

        // Added the key of the tax item so that the code is unique
        $code .= $taxItem['jurisdiction']['display_name'] . $key;

        return $code;
    }

    private function getTaxTitle($taxItem)
    {
        return sprintf('%s - %s', $taxItem['jurisdiction']['display_name'], $taxItem['tax_rate_details']['display_name']);
    }
}
