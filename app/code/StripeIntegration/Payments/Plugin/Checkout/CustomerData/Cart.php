<?php

namespace StripeIntegration\Payments\Plugin\Checkout\CustomerData;

use StripeIntegration\Payments\Helper\Stripe\PaymentMethodMessagingElement;

class Cart
{
    private $messagingElementHelper;

    public function __construct(
        PaymentMethodMessagingElement $messagingElementHelper
    ) {
        $this->messagingElementHelper = $messagingElementHelper;
    }

    public function afterGetSectionData(
        \Magento\Checkout\CustomerData\Cart $subject,
        array $result
    ) {
        $result['messagingElement'] = $this->messagingElementHelper->getPaymentMethodMessagingOptions();

        return $result;
    }
}