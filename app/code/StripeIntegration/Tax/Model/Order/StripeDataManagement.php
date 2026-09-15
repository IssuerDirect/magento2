<?php
namespace StripeIntegration\Tax\Model\Order;

use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Model\Order;

/**
 * Data transferring between quote address and order
 */
class StripeDataManagement
{
    private $stripeTaxCalculationId;
    private $stripeFlatFees;
    private $flatFeesInvoiced;

    public function __construct()
    {
        $this->stripeTaxCalculationId = [];
        $this->stripeFlatFees = [];
        $this->flatFeesInvoiced = [];
    }

    public function setFromAddressData(Order $order, QuoteAddress $address)
    {
        if ($address->getData('stripe_tax_calculation_id') && $order->getIncrementId() !== null) {
            $this->stripeTaxCalculationId[$order->getIncrementId()] = $address->getData('stripe_tax_calculation_id');
        }

        if (($feesJson = $address->getData('stripe_flat_fees')) && $order->getIncrementId() !== null) {
            $this->stripeFlatFees[$order->getIncrementId()] = $feesJson;
        }

        return $order;
    }

    public function setDataFrom(Order $order)
    {
        if ($order->getIncrementId() !== null && isset($this->stripeTaxCalculationId[$order->getIncrementId()])) {
            $order->setData('stripe_tax_calculation_id', $this->stripeTaxCalculationId[$order->getIncrementId()]);
        }

        if ($order->getIncrementId() !== null && isset($this->stripeFlatFees[$order->getIncrementId()])) {
            $order->setData('stripe_flat_fees', $this->stripeFlatFees[$order->getIncrementId()]);
        }

        return $order;
    }

    /**
     * Returns true if the flat fees have already been added to an invoice for this order
     * in the current request. Uses the order entity_id which is always present when invoicing.
     */
    public function isFlatFeesInvoiced($orderId)
    {
        if ($orderId === null) {
            return false;
        }

        return isset($this->flatFeesInvoiced[$orderId]);
    }

    public function markFlatFeesInvoiced($orderId)
    {
        if ($orderId === null) {
            return;
        }

        $this->flatFeesInvoiced[$orderId] = true;
    }
}
