<?php

namespace StripeIntegration\Payments\Gateway\Config\BankTransfers;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;

class CanVoidValueHandler implements ValueHandlerInterface
{
    /**
     * Handler for the canVoid() check of the Stripe bank transfer payment method
     *
     * @param array $subject
     * @param $storeId
     * @return bool
     */
    public function handle(array $subject, $storeId = null)
    {
        $payment = $subject['payment']->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        // If it has invoice_id it means it was a bank transfer created from the admin
        if (isset($additionalInformation['invoice_id']) && $additionalInformation['invoice_id']) {
            return true;
        }

        // If it has invoice_id it means it was a bank transfer created from the frontend
        if (isset($additionalInformation['token']) && $additionalInformation['token']) {
            return true;
        }

        return false;
    }
}
