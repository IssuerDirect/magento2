<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order;

use Magento\Sales\Api\Data\OrderPaymentInterface;

class Payment
{
    /**
     * In case of bank transfers created from the admin, there is no transaction created for them.
     * In case of invoice created from admin, there is a condition in the original method which fails, as the transaction
     * is seen as closed.
     * In these cases, the result of the canVoid method should only be the call to the canVoid() method of the
     * payment method instance
     *
     * @param OrderPaymentInterface $subject
     * @param callable $proceed
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundCanVoid(OrderPaymentInterface $subject, callable $proceed)
    {
        // Only return something else than the original method if the payment method is bank transfers or invoice
        $methods = ['stripe_payments_bank_transfers', 'stripe_payments_invoice'];
        if (in_array($subject->getMethod(), $methods)) {
            return $subject->getMethodInstance()->canVoid();
        }

        return $proceed();
    }
}
