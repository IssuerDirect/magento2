define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/mage',
    'domReady!'
], function($, customerData) {
    'use strict';

    return function(config, element) {
        var $rootElement = $(element);

        var getRow = function(id) {
            return $rootElement.find('tr.' + id);
        };

        var editSubscription = function(subscriptionId, section) {
            var $row = getRow(subscriptionId);
            $row.find('.stripe-subscription-edit .mutable.section', 'tr.' + subscriptionId).hide();
            $row.find('.stripe-subscription-edit.' + section + '.' + subscriptionId + ' .mutable.section', 'tr.' + subscriptionId).show();
            $row.find('.stripe-subscription-edit.' + subscriptionId + ' .static.section', 'tr.' + subscriptionId).hide();
        };

        var cancelEditSubscription = function(subscriptionId) {
            var $row = getRow(subscriptionId);
            $row.find('.stripe-subscription-edit.' + subscriptionId + ' .mutable.section', 'tr.' + subscriptionId).hide();
            $row.find('.stripe-subscription-edit.' + subscriptionId + ' .static.section', 'tr.' + subscriptionId).show();
        };

        $rootElement.on('click', '[data-action="edit-subscription"]', function() {
            editSubscription($(this).data('subscription-id'), $(this).data('section'));
        });

        $rootElement.on('click', '[data-action="cancel-edit-subscription"]', function() {
            cancelEditSubscription($(this).data('subscription-id'));
        });

        customerData.initStorage();
        customerData.invalidate(['cart', 'cart-data', 'checkout-data']);
        // customerData.reload(['cart'], true);

        $rootElement.on('click', 'button.update', function () {
            customerData.invalidate(['cart']);
            // customerData.reload(['cart'], true);
        });
    };
});