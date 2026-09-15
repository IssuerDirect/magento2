<?php

namespace StripeIntegration\Payments\Gateway\Command\BankTransfers;

use Magento\Payment\Gateway\CommandInterface;

class CancelCommand implements CommandInterface
{
    private $config;
    private $areaCodeHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper
    ) {
        $this->config = $config;
        $this->areaCodeHelper = $areaCodeHelper;
    }

    public function execute(array $commandSubject): void
    {
        $payment = $commandSubject['payment']->getPayment();
        $stripeToken = $payment->getAdditionalInformation('token');

        // Only run the code when the bank transfer is created from the frontend
        // For bank transfers that are created in the admin, the invoice cancellation kicks in, and does not require
        // any specific actions from this command. This could change if cancellation from admin will be implemented.
        if ($stripeToken) {
            // The cancel process will be started by cancelling the payment intent. This will trigger the Payment intent
            // cancelling webhook handler, which will cancel the order.
            $cancellationReason = [
                "cancellation_reason" => "abandoned"
            ];

            // If cancellation is done from admin, set the reason as requested by the customer
            if ($this->areaCodeHelper->isAdmin()) {
                $cancellationReason = [
                    "cancellation_reason" => "requested_by_customer"
                ];
            }

            $this->config->getStripeClient()->paymentIntents->cancel($payment->getLastTransId(), $cancellationReason);
        }
    }
}
