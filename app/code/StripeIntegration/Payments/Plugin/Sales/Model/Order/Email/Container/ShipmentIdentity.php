<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Container;

class ShipmentIdentity
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->config = $config;
    }

    public function afterGetTemplateId(
        \Magento\Sales\Model\Order\Email\Container\ShipmentIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionShipmentEmailTemplate() ?? $result;
    }

    public function afterGetGuestTemplateId(
        \Magento\Sales\Model\Order\Email\Container\ShipmentIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionGuestShipmentEmailTemplate() ?? $result;
    }
}