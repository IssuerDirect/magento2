<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Framework\Event\Observer;

// sales_quote_collect_totals_before
class QuoteObserver extends AbstractDataAssignObserver
{
    public $hasSubscriptions = null;

    private $taxCalculation;
    private $quoteHelper;
    private $configHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Model\Tax\Calculation $taxCalculation
    )
    {
        $this->quoteHelper = $quoteHelper;
        $this->taxCalculation = $taxCalculation;
        $this->configHelper = $configHelper;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->setTaxCalculationMethod($observer);
    }

    public function setTaxCalculationMethod($observer)
    {
        $quote = $observer->getEvent()->getQuote();
        $isSubscriptionsEnabled = $this->configHelper->getConfigData("payment/stripe_payments_subscriptions/active", $quote->getStoreId());

        if (empty($quote) || !$isSubscriptionsEnabled)
            return;

        $this->taxCalculation->method = null;

        if ($this->hasSubscriptions === null)
            $this->hasSubscriptions = $this->quoteHelper->hasSubscriptionsIn($quote->getAllItems());

        if ($this->hasSubscriptions)
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }

        if ($quote->getPayment() && $quote->getPayment()->getMethod() == "stripe_payments_invoice")
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }
    }
}
