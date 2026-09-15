<?php

namespace StripeIntegration\Payments\Gateway\Command\Invoice;

use Magento\Payment\Gateway\CommandInterface;

class CancelCommand implements CommandInterface
{
    public function execute(array $commandSubject): void
    {
        // The cron that canceled the order threw an exception that the command pool was not configured if the method does not exist.
        // If there will be a manual cancellation process, we will need to add the commands here as well and make
        // sure there is no overlapping with the cron cancellation process.
    }
}
