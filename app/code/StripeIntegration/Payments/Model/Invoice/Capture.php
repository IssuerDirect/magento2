<?php

namespace StripeIntegration\Payments\Model\Invoice;

class Capture
{
    private $invoice = null;

    /**
     * Set the invoice being captured
     *
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     * @return void
     */
    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;
    }

    /**
     * Get the invoice being captured
     *
     * @return \Magento\Sales\Model\Order\Invoice|null
     */
    public function getInvoice()
    {
        return $this->invoice;
    }

    /**
     * Clear the stored invoice
     *
     * @return void
     */
    public function clearInvoice()
    {
        $this->invoice = null;
    }
}
