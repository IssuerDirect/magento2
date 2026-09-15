define([],
    function() {
    'use strict';

    return function(config)
    {
        // Loading this component so it is not dependent on domReady
        window.checkoutConfig = window.checkoutConfig || {};
        window.checkoutConfig.storeCode = window.checkoutConfig.storeCode || config.storeCode;
    };
});