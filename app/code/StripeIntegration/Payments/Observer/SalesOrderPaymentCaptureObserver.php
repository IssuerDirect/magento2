<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class SalesOrderPaymentCaptureObserver implements ObserverInterface
{
    private $invoiceCapture;

    public function __construct(
        \StripeIntegration\Payments\Model\Invoice\Capture $invoiceCapture
    )
    {
        $this->invoiceCapture = $invoiceCapture;
    }

    /**
     * Store the invoice being captured
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $invoice = $observer->getEvent()->getInvoice();

        if ($invoice) {
            $this->invoiceCapture->setInvoice($invoice);
        }
    }
}
