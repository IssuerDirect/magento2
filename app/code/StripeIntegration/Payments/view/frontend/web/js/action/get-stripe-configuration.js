define(
    [
        'mage/url',
        'mage/storage'
    ],
    function (urlBuilder, storage) {
        'use strict';

        return function (callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/get_stripe_configuration', {});

            return storage.get(serviceUrl)
                .done(function (response) {
                    try {
                        var stripeConfiguration = JSON.parse(response);
                        if (callback && typeof callback === 'function') {
                            callback(stripeConfiguration);
                        }
                    } catch (error) {
                        console.error('Failed to parse Stripe configuration: ', error);
                    }
                })
                .fail(function(response) {
                    console.error('Failed to fetch Stripe configuration: ', response);
                });
        };
    }
);