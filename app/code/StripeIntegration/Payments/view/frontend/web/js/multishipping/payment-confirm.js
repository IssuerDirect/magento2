define([
    'uiLayout',
    'jquery',
    'StripeIntegration_Payments/js/stripe',
    'StripeIntegration_Payments/js/action/place-multishipping-order',
    'StripeIntegration_Payments/js/action/finalize-multishipping-order',
    'domReady!'
], function(layout, $, stripe, placeOrder, finalizeOrder) {
    'use strict';

    return function(config)
    {
        var overviewPostButton = $('#review-button');
        if (overviewPostButton.length === 0)
        {
            alert( $.mage.__("Sorry, the selected payment method is not available. Please use a different payment method.") );
            window.history.back();
        }

        if (!config.hasPaymentMethod)
        {
            $.mage.redirect(config.billingUrl);
        }

        var onComplete = function()
        {
            overviewPostButton.removeClass('disabled');
        };

        var onAuthenticationRequired = function(clientSecret)
        {
            overviewPostButton.addClass('disabled');
            stripe.authenticateCustomer(clientSecret, finalizeOrder);
        };

        stripe.initStripe(config.stripeParams, function(err)
        {
            if (err)
            {
                alert( $.mage.__("Sorry, the selected payment method is not available. Please use a different payment method.") );
                overviewPostButton.addClass('disabled');
                console.error(err);
            }
        });

        overviewPostButton.click(function(e)
        {
            e.preventDefault();
            e.stopPropagation();

            overviewPostButton.addClass('disabled');
            placeOrder(onComplete, onAuthenticationRequired);
        });
    };
});