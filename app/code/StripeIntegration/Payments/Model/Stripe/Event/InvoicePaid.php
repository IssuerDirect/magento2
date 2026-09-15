<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class InvoicePaid
{
    use StripeObjectTrait;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);
    }
    public function process($arrEvent, $object)
    {
        // Avoid mutating orders in multiple events, it can lead to race conditions and data corruption
    }
}