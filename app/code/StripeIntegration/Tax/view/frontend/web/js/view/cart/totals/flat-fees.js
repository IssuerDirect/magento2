define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/totals'
], function (Component, totals) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'StripeIntegration_Tax/cart/totals/flat-fees'
        },

        /**
         * Reads the current quote total segments and returns only those injected by
         * CartTotalRepositoryPlugin, identified by the 'stripe_flat_fee_' code prefix.
         * Each matching segment is mapped to a plain object with a title and value so
         * the template can iterate and render one row per fee type without knowing
         * the specific fee codes in advance.
         */
        getFees: function () {
            var segments = totals.totals() ? totals.totals()['total_segments'] : [];

            return segments.filter(function (segment) {
                return segment['code'].indexOf('stripe_flat_fee_') === 0 && segment['value'] > 0;
            }).map(function (segment) {
                return {
                    title: segment['title'],
                    value: segment['value']
                };
            });
        },

        getFormattedValue: function (value) {
            return this.getFormattedPrice(value);
        },

        isVisible: function () {
            return this.getFees().length > 0;
        }
    });
});
