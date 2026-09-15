define(
    [
        'mage/url',
        'mage/storage'
    ],
    function (
        urlBuilder,
        storage
    ) {
        'use strict';
        return function (paymentMethodId, subscriptionId, callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/add_payment_method');

            var payload = {
                paymentMethodId: paymentMethodId,
                subscriptionId: subscriptionId
            };

            return storage.post(serviceUrl, JSON.stringify(payload)).always(callback);
        };
    }
);
