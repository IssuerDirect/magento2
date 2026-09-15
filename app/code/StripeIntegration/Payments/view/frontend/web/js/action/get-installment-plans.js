define(
    [
        'mage/url',
        'mage/storage'
    ],
    function (urlBuilder, storage) {
        'use strict';

        return function ()
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/get_installment_plans', {});

            return storage.post(serviceUrl);
        };
    }
);