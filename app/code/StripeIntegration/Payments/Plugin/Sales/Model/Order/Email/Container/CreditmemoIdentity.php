<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Container;

class CreditmemoIdentity
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->config = $config;
    }

    public function afterGetTemplateId(
        \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionCreditmemoEmailTemplate() ?? $result;
    }

    public function afterGetGuestTemplateId(
        \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionGuestCreditmemoEmailTemplate() ?? $result;
    }
}