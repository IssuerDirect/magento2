define([
    'uiComponent',
    'ko',
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/customer-data'
], function (Component, ko, quote, customerData) {
    'use strict';

    return Component.extend({
        initialize: function () {
            this._super();
            var last = null;

            var billingCountry = ko.computed(function () {
                var addr = quote.billingAddress();
                return addr && addr.countryId ? addr.countryId : null;
            });

            // When the billing country changes, invalidate minicart so that it takes the new country when
            // displaying the payment method messaging element
            billingCountry.subscribe(function (current) {
                if (current && current !== last) {
                    customerData.invalidate(['cart']);
                    last = current;
                }
            });

            return this;
        }
    });
});