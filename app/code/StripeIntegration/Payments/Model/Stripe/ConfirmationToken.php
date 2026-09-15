<?php

namespace StripeIntegration\Payments\Model\Stripe;

class ConfirmationToken
{
    use StripeObjectTrait;

    private $objectSpace = 'confirmationTokens';

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);
    }

    public function fromId($id)
    {
        if (!empty($this->getStripeObject()->id) && $this->getStripeObject()->id == $id)
        {
            return $this;
        }

        $this->load($id);
        return $this;
    }

    public function fromObject($object)
    {
        $this->setObject($object);
        return $this;
    }

    public function getSetupFutureUsage()
    {
        return $this->getStripeObject()->setup_future_usage ?? null;
    }

    public function getPaymentMethodPreview()
    {
        return $this->getStripeObject()->payment_method_preview ?? null;
    }
}
