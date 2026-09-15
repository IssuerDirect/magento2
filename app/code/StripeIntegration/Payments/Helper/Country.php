<?php

namespace StripeIntegration\Payments\Helper;

class Country
{
    private $quoteHelper;

    public function __construct(
        Quote $quoteHelper
    ) {
        $this->quoteHelper = $quoteHelper;
    }

    public function getCountryCodeFromQuoteBillingAddress()
    {
        $quote = $this->quoteHelper->getQuote();
        if ($quote->getId()) {
            $countryCode = $quote->getBillingAddress()->getCountry();

            if ($countryCode) {
                return $countryCode;
            }
        }

        return null;
    }
}