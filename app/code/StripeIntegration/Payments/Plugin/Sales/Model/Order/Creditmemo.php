<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order;

use StripeIntegration\Payments\Helper\Dispute;

class Creditmemo
{
    public function afterCanRefund($subject, $result)
    {
        if ($subject->getOrder()->getState() === Dispute::STRIPE_DISPUTE_STATE_CODE) {
            return false;
        }

        return $result;
    }
}
