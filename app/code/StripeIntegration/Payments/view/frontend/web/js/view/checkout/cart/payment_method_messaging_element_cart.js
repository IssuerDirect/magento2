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

            initialize: function()
            {
                this._super();

                this.cart = customerData.get('cart');
                // Limit the number of times the change event is fired. In this case the event is fired 500 ms after
                // nothing else happens on the cart-data component.
                this.cartData = customerData.get('cart-data').extend({rateLimit: 500});

                this.paymentMethodTypes = ko.computed(function() {
                    if (this.cart().messagingElement && Array.isArray(this.cart().messagingElement.paymentMethodTypes)) {
                        return this.cart().messagingElement.paymentMethodTypes;
                    }
                    return null;
                }, this);

                this.messagingElement = ko.observable({});
                // Form messaging element details from cart-data once changes are made to the frontend.
                // The changes include the page being loaded, changing the country or changing quantities.
                this.cartData.subscribe(function (data) {
                    var messagingElement = {};
                    if (data.address && data.address.countryId) {
                        messagingElement.countryCode = data.address.countryId;
                    }
                    if (data.totals) {
                        messagingElement.currency = data.totals.quote_currency_code;
                        messagingElement.amount = helper.convertToStripeAmount(data.totals.grand_total, messagingElement.currency);
                    }
                    if (this.paymentMethodTypes() !== null) {
                        messagingElement.paymentMethodTypes = this.paymentMethodTypes();
                    }

                    this.messagingElement(messagingElement);
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

                this.messagingElement.subscribe(function (details) {
                    if (this.stripeElements) {
                        var container = document.getElementById(this.selector);
                        if (container) {
                            container.innerHTML = '';
                        }
                        this.displayMessagingElement(details);
                    }
                    customerData.invalidate(['cart']);
                }, this);
            },

            displayMessagingElement: function(messagingElementDetails)
            {
                var self = this;
                if (messagingElementDetails === null) {
                    messagingElementDetails = this.messagingElement();
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