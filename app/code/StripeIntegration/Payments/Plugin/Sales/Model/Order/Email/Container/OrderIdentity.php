<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Container;

class OrderIdentity
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->config = $config;
    }

    public function afterGetTemplateId(
        \Magento\Sales\Model\Order\Email\Container\OrderIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionOrderEmailTemplate()
            ?? $this->config->getSubscriptionChangeOrderEmailTemplate()
            ?? $result;
    }

    public function afterGetGuestTemplateId(
        \Magento\Sales\Model\Order\Email\Container\OrderIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionGuestOrderEmailTemplate() ?? $result;
    }
}