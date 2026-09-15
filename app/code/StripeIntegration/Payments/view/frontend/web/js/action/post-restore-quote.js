define(
    [
        'mage/url',
        'mage/storage',
        'Magento_Customer/js/customer-data'
    ],
    function (
        urlBuilder,
        storage,
        customerData
    ) {
        'use strict';
        return function (callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/restore_quote');

            customerData.invalidate(['cart']);

            return storage.post(serviceUrl).always(callback);
        };
    }
);
