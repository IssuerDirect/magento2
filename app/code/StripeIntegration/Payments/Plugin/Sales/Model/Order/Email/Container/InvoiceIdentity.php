<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Email\Container;

class InvoiceIdentity
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->config = $config;
    }

    public function afterGetTemplateId(
        \Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionInvoiceEmailTemplate() ?? $result;
    }

    public function afterGetGuestTemplateId(
        \Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject,
        $result
    ) {
        return $this->config->getSubscriptionGuestInvoiceEmailTemplate() ?? $result;
    }
}