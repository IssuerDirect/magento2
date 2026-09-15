define([
        'uiComponent',
        'StripeIntegration_Payments/js/action/get-stripe-configuration',
        'StripeIntegration_Payments/js/stripe',
        'StripeIntegration_Payments/js/helper/payment_method_messaging_element'
    ],
    function (
        Component,
        getStripeConfiguration,
        stripe,
        helper
    )
    {
        'use strict';

        return Component.extend({

            initialize: function(elementDetails)
            {
                this._super();

                if (helper.checkMessagingElementDetails(elementDetails)) {
                    getStripeConfiguration(function (configuration) {
                        stripe.initStripe(configuration, function(err) {
                            var elements = stripe.stripeJs.elements();
                            var paymentMessagingElement = elements.create('paymentMethodMessaging', elementDetails);
                            paymentMessagingElement.on('loaderror', function(event) {
                                helper.loadErrorMessage(event);
                            });
                            paymentMessagingElement.mount('#payment-method-messaging-element');
                        });
                    });
                }
            }
        });
    });