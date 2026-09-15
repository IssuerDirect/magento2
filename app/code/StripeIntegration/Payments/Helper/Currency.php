<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class Currency
{
    private $convert;
    private $storeManager;
    private $priceCurrency;

    public function __construct(
        \StripeIntegration\Payments\Helper\Convert $convert,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
    ) {
        $this->convert = $convert;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
    }

    public function addCurrencySymbol($magentoAmount, $currencyCode = null)
    {
        if (empty($currencyCode))
            $currencyCode = $this->getCurrentCurrencyCode();

        $precision = $this->convert->getCurrencyPrecision($currencyCode);

        return $this->priceCurrency->format($magentoAmount, false, $precision, null, strtoupper($currencyCode));
    }

    public function getCurrentCurrencyCode()
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function getFormattedStripeAmount($stripeAmount, $currency, $order)
    {
        $orderAmount = $this->convert->stripeAmountToOrderAmount($stripeAmount, $currency, $order);

        return $this->addCurrencySymbol($orderAmount, $currency);
    }

    public function formatStripePrice($stripeAmount, string $currency)
    {
        $precision = $this->convert->getCurrencyPrecision($currency);
        $magentoAmount = $this->convert->stripeAmountToMagentoAmount($stripeAmount, $currency);

        return $this->priceCurrency->format($magentoAmount, false, $precision, null, strtoupper($currency));
    }

    public function getCurrencyPrecision(string $currency): int
    {
        return $this->convert->getCurrencyPrecision($currency);
    }

    /**
     * When the currency is known to Magento (i.e. it is an allowed currency with an exchange rate
     * to the base currency), this delegates to formatStripePrice() so the output is consistent
     * with all other prices in the store.
     * When the currency is NOT in Magento's system (e.g. an Adaptive Pricing presentment currency
     * that the merchant has not configured), priceCurrency->getCurrency() falls back to the base
     * currency. In that case we return the currency code and the Magento formatted amount.
     *
     * @param int $stripeAmount
     * @param string $currency
     */
    public function formatStripePriceWithFallback(int $stripeAmount, string $currency): string
    {
        $currency = strtoupper($currency);
        $currencyObject = $this->priceCurrency->getCurrency(null, $currency);

        if (strtoupper((string)$currencyObject->getCurrencyCode()) === $currency) {
            return $this->formatStripePrice($stripeAmount, $currency);
        }

        $magentoAmount = $this->convert->stripeAmountToMagentoAmount($stripeAmount, $currency);

        return $currency . ' ' . $magentoAmount;
    }
}