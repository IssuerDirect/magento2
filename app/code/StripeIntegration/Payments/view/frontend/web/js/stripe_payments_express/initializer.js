define([
    'StripeIntegration_Payments/js/stripe_payments_express',
    'Magento_Customer/js/customer-data'
], function(stripeExpress, customerData) {
    'use strict';

    return function(config, element) {
        var cart = customerData.get('cart');

        var initECE = function() {
            stripeExpress.initStripeExpress(
                '#' + element.id,
                config.walletParams,
                config.locationDetails,
                config.buttonConfig,
                getCallback()
            );
        };

        var getCallback = function() {
            switch (config.locationDetails.location) {
                case 'cart':
                    return stripeExpress.initCartWidget.bind(stripeExpress);
                case 'minicart':
                    return stripeExpress.initMiniCartWidget.bind(stripeExpress);
                case 'product':
                    return stripeExpress.initProductWidget.bind(stripeExpress);
            }
        };

        initECE();

        cart.subscribe(function ()
        {
            // Wait for Magento to commit the changes before re-initializing the ECE
            setTimeout(initECE, 500);
        });
    };
});