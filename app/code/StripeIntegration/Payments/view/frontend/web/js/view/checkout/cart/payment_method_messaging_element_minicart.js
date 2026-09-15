define([
        'ko',
        'uiComponent',
        'StripeIntegration_Payments/js/action/get-stripe-configuration',
        'StripeIntegration_Payments/js/stripe',
        'Magento_Customer/js/customer-data',
        'StripeIntegration_Payments/js/helper/payment_method_messaging_element'
    ],
    function (
        ko,
        Component,
        getStripeConfiguration,
        stripe,
        customerData,
        helper
    )
    {
        'use strict';

        return Component.extend({
            stripeElements: null,
            messagingElementSubscription: null,

            initialize: function()
            {
                this._super();

                this.cart = customerData.get('cart');

                this.messagingElement = ko.computed(function() {
                    return this.cart().messagingElement;
                }, this);

                this.cart.subscribe(function(newCart) {
                    if ((!newCart.summary_count || newCart.summary_count === 0) &&
                        this.messagingElementSubscription !== null
                    ) {
                        this.messagingElementSubscription.dispose();
                    }
                }, this);

                return this;
            },

            onDomReady: function()
            {
                var self = this;
                getStripeConfiguration(function (configuration) {
                    stripe.initStripe(configuration, function(err) {
                        self.stripeElements = stripe.stripeJs.elements();
                        self.displayMessagingElement(null);
                    });
                });

                this.messagingElementSubscription = this.messagingElement.subscribe(function(messagingElement) {
                    if (this.stripeElements) {
                        var container = document.getElementById(this.selector);
                        if (container) {
                            container.innerHTML = '';
                        }
                        this.displayMessagingElement(messagingElement);
                    }
                }, this);
            },

            displayMessagingElement: function(messagingElementDetails)
            {
                var self = this;
                if (messagingElementDetails === null) {
                    messagingElementDetails = this.cart().messagingElement;
                }
                if (helper.checkMessagingElementDetails(messagingElementDetails)) {
                    if (this.stripeElements) {
                        var paymentMessagingElement = this.stripeElements.create('paymentMethodMessaging', messagingElementDetails);
                        paymentMessagingElement.on('loaderror', function(event) {
                            helper.loadErrorMessage(event);
                        });
                        paymentMessagingElement.mount('#' + self.selector);
                    }
                }
            }

        });
    });