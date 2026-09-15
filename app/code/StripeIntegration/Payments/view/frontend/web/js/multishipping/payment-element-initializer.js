define([
    'uiLayout',
    'jquery'
], function (layout, $) {
    'use strict';

    return function (config, element) {
        // Store the init parameters globally for the payment element component to access
        window.initParams = config.initParams;

        // Initialize the UI layout for the payment element
        layout([
            {
                component: 'StripeIntegration_Payments/js/view/multishipping/method-renderer/payment_element',
                name: 'payment_method_stripe_payments',
                method: 'stripe_payments',
                item: {
                    method: 'stripe_payments'
                },
                captureMethod: config.captureMethod
            }
        ]);
    };
});
