<?php

namespace StripeIntegration\Payments\Plugin\Framework\Event;

use Magento\Framework\Event\ConfigInterface;
use StripeIntegration\Payments\Helper\RedirectInvoice;

class Config
{
    private $redirectInvoice;

    public function __construct(
        RedirectInvoice $redirectInvoice
    ) {
        $this->redirectInvoice = $redirectInvoice;
    }

    public function afterGetObservers(
        ConfigInterface $subject,
        array $result,
        $eventName
    ): array {
        if ($eventName === 'sales_order_invoice_register' && $this->redirectInvoice->shouldSuppressInvoiceRegisterEvent()) {
            $this->redirectInvoice->clearSuppressInvoiceRegisterEvent();
            return [];
        }

        return $result;
    }
}
