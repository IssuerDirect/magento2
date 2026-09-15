<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Checkout\Block\Cart;

class Sidebar
{
    private $config;
    private $request;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->config = $config;
        $this->request = $request;
    }

    /**
     * Added because the component is added to the jsLayout, so even if it was set to be disabled from the location
     * in admin, the JS file was still loaded. With this method we take out the component from the jsLayout if the
     * conditions are respected.
     *
     * @param \Magento\Checkout\Block\Cart\Sidebar $subject
     * @param $result
     * @return false|mixed|string
     */
    public function afterGetJsLayout(
        \Magento\Checkout\Block\Cart\Sidebar $subject,
                                            $result
    ) {
        // If the PMME is disabled on the minicar page or
        // the user is on cart page and the PMME is enabled for cart page,
        // remove the minicart PMME component from the jsLayout
        if (!$this->config->isPaymentMessagingElementEnabledOnMinicart() ||
            ($this->isCartPage() && $this->config->isPaymentMessagingElementEnabledOnCart())
        ) {
            // Check if component exists and remove it if it does
            $decodedResult = json_decode($result, true);
            if (isset($decodedResult['components']['minicart_content']['children']['subtotal.container']['children']['payment_method_messaging_element_minicart'])) {
                unset($decodedResult['components']['minicart_content']['children']['subtotal.container']['children']['payment_method_messaging_element_minicart']);
            }

            return json_encode($decodedResult);
        }

        return $result;
    }

    public function isCartPage(): bool
    {
        return $this->request->getRouteName() === 'checkout' &&
            $this->request->getControllerName() === 'cart' &&
            $this->request->getActionName() === 'index';
    }
}