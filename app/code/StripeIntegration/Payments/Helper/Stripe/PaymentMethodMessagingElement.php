<?php

namespace StripeIntegration\Payments\Helper\Stripe;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use StripeIntegration\Payments\Helper\Convert;
use StripeIntegration\Payments\Helper\Country;
use StripeIntegration\Payments\Helper\Currency;
use StripeIntegration\Payments\Helper\Product;
use StripeIntegration\Payments\Helper\Quote;

class PaymentMethodMessagingElement
{
    private $productHelper;
    private $priceCurrency;
    private $currencyHelper;
    private $convertHelper;
    private $quoteHelper;
    private $countryHelper;

    public function __construct(
        Product $productHelper,
        PriceCurrencyInterface $priceCurrency,
        Currency $currencyHelper,
        Convert $convertHelper,
        Quote $quoteHelper,
        Country $countryHelper
    ) {
        $this->productHelper = $productHelper;
        $this->priceCurrency = $priceCurrency;
        $this->currencyHelper = $currencyHelper;
        $this->convertHelper = $convertHelper;
        $this->quoteHelper = $quoteHelper;
        $this->countryHelper = $countryHelper;
    }

    public function getProductPrice($productId)
    {
        try {
            $product = $this->productHelper->getProduct($productId);
            $basePrice = $this->productHelper->getPrice($product);
            $convertedPrice = $this->priceCurrency->convert($basePrice);
            $currencyCode = $this->currencyHelper->getCurrentCurrencyCode();

            return $this->convertHelper->magentoAmountToStripeAmount($convertedPrice, $currencyCode);
        } catch (NoSuchEntityException $e) {
            return 0;
        }
    }

    public function getCurrencyCode()
    {
        return $this->currencyHelper->getCurrentCurrencyCode();
    }

    public function getGrandTotal()
    {
        $grandTotal = $this->quoteHelper->getQuote()->getGrandTotal();

        return $this->convertHelper->magentoAmountToStripeAmount($grandTotal, $this->currencyHelper->getCurrentCurrencyCode());
    }

    public function getPaymentMethodMessagingOptions($productId = null)
    {
        $options = [
            'currency' => $this->getCurrencyCode()
        ];

        if ($productId) {
            $options['amount'] = $this->getProductPrice($productId);
        } else {
            $options['amount'] = $this->getGrandTotal();
        }

        // Only get the country code if it is set on the billing address.
        // The Stripe js component will use geoIP if the country code is not provided.
        $countryCode = $this->countryHelper->getCountryCodeFromQuoteBillingAddress();
        if ($countryCode) {
            $options['countryCode'] = $countryCode;
        }

        // Only send the payment methods if they are set. This is not a mandatory field for the component.
        $paymentMethodTypes = $this->getPaymentMethodTypes();
        if (!empty($paymentMethodTypes)) {
            $options['paymentMethodTypes'] = $paymentMethodTypes;
        }

        return $options;
    }

    /**
     * Added for overriding purposes if merchants want to limit the payment methods which are displayed.
     *
     * @return array
     */
    public function getPaymentMethodTypes()
    {
        return [];
    }
}