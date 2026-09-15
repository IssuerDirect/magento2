define([
    'uiLayout'
], function(layout) {
    'use strict';

    return function(config) {
        window.initParams = config.initParams;

        var component = 'StripeIntegration_Payments/js/view/ui_components/setup_element';
        if (config.mode && config.mode === "new_subscription_payment_method") {
            component = 'StripeIntegration_Payments/js/view/ui_components/setup_element/new_subscription_payment_method';
        }

        layout([
            {
                component: component,
                name: 'payment_method_stripe_payments',
                method: 'stripe_payments',
                item: {
                    method: 'stripe_payments'
                }
            }
        ]);
    };
});