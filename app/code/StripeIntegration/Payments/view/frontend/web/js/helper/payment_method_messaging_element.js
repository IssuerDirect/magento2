define(
    [
        'ko'
    ],
    function (
        ko
    ) {
        'use strict';

        return {
            zeroDecimalCurrencies: ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'],
            threeDecimalCurrencies: ['BHD','JOD','KWD','OMR','TND'],
            allowedCurrencies: ['USD', 'GBP', 'EUR', 'DKK', 'NOK', 'SEK', 'AUD', 'CAD', 'NZD', 'PLN', 'CZK', 'CHF'],
            allowedCountries: ['AT', 'AU', 'BE', 'CA', 'CH', 'CZ', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'GR', 'IE', 'IT', 'NL', 'NO', 'NZ', 'PL', 'PT', 'RO', 'SE', 'US'],

            convertToStripeAmount: function(amount, currencyCode)
            {
                if (!currencyCode) {
                    return null;
                }

                var code = currencyCode.toUpperCase();

                if (this.zeroDecimalCurrencies.indexOf(code) >= 0) {
                    return Math.round(amount);
                } else if (this.threeDecimalCurrencies.indexOf(code) >= 0) {
                    return Math.round(amount * 100) * 10;
                } else {
                    return Math.round(amount * 100);
                }
            },

            checkMessagingElementDetails: function(messagingElementDetails) {
                if (!messagingElementDetails.hasOwnProperty('amount') || messagingElementDetails.amount <= 0) {
                    return false;
                }

                if (!messagingElementDetails.hasOwnProperty('currency') || this.allowedCurrencies.indexOf(messagingElementDetails.currency) < 0) {
                    return false;
                }

                if (messagingElementDetails.hasOwnProperty('countryCode') && this.allowedCountries.indexOf(messagingElementDetails.countryCode) < 0) {
                    return false;
                }

                if (messagingElementDetails.hasOwnProperty('paymentMethodTypes') && !Array.isArray(messagingElementDetails.paymentMethodTypes)) {
                    return false;
                }

                return true;
            },

            loadErrorMessage: function(event)
            {
                if (event && event.error && event.error.message)
                {
                    console.warn(event.error.message);
                }
                else
                {
                    console.warn('Could not load the Payment Method Messaging Element.');
                }
            }
        };
    }
);
