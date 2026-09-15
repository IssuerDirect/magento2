<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Checkout\Block\Cart;

class Totals
{
    private $config;


    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->config = $config;
    }

    /**
     * Added because the component is added to the jsLayout, so even if it was set to be disabled from the location
     * in admin, the JS file was still loaded. With this method if the config is disabled, we take out the component
     * from the jsLayout.
     *
     * @param \Magento\Checkout\Block\Cart\Totals $subject
     * @param $result
     * @return false|mixed|string
     */
    public function afterGetJsLayout(
        \Magento\Checkout\Block\Cart\Totals $subject,
        $result
    ) {
        // If enabled on the location, return the jsLayout as it is
        if ($this->config->isPaymentMessagingElementEnabledOnCart()) {
            return $result;
        }

        // Check if component exists and remove it if it does
        $decodedResult = json_decode($result, true);
        if (isset($decodedResult['components']['block-totals']['children']['payment_method_messaging_element_cart'])) {
            unset($decodedResult['components']['block-totals']['children']['payment_method_messaging_element_cart']);
        }

        return json_encode($decodedResult, JSON_HEX_TAG);
    }
}