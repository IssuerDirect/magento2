<?php

namespace StripeIntegration\Payments\Gateway\Config\Invoice;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;

class CanVoidValueHandler implements ValueHandlerInterface
{
    /**
     * Handler for the canVoid() check of the Stripe invoice payment method
     *
     * @param array $subject
     * @param $storeId
     * @return bool
     */
    public function handle(array $subject, $storeId = null)
    {
        $payment = $subject['payment']->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if (isset($additionalInformation['invoice_id']) && $additionalInformation['invoice_id']) {
            return true;
        }

        return false;
    }
}
