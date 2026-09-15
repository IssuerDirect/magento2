<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Refund
{
    use StripeObjectTrait;

    private $objectSpace = 'refunds';

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);
    }

    public function fromRefundId($id)
    {
        if (!empty($this->getStripeObject()->id) && $this->getStripeObject()->id == $id)
        {
            return $this;
        }

        $this->load($id);
        return $this;
    }

    public function fromObject(\Stripe\Refund $refund)
    {
        $this->setObject($refund);
        return $this;
    }

    public function getAmount()
    {
        return (int)$this->getStripeObject()->amount;
    }

    public function getCurrency()
    {
        return $this->getStripeObject()->currency;
    }

    public function getStatus()
    {
        return $this->getStripeObject()->status;
    }

    public function getCreatedTimestamp()
    {
        return $this->getStripeObject()->created;
    }

    public function isPending()
    {
        return $this->getStatus() == 'pending';
    }

    public function isFailed()
    {
        return $this->getStatus() == 'failed';
    }

    public function isSucceeded()
    {
        return $this->getStatus() == 'succeeded';
    }
}
