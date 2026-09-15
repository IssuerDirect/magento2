<?php

namespace StripeIntegration\Payments\Model\Stripe;

class PaymentMethod
{
    use StripeObjectTrait;

    private $objectSpace = 'paymentMethods';
    private $loggerHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool
    )
    {
        $this->loggerHelper = $loggerHelper;
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);
    }

    public function fromPaymentMethodId($id)
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

    public function getCustomerId()
    {
        if (empty($this->getStripeObject()->customer))
        {
            return null;
        }

        if (!empty($this->getStripeObject()->customer->id))
        {
            return $this->getStripeObject()->customer->id;
        }

        return $this->getStripeObject()->customer;
    }

    public function isAttachedToCustomer()
    {
        return !empty($this->getCustomerId());
    }

    public function getPaymentMethodType()
    {
        return $this->getStripeObject()->type ?? null;
    }

    public function getCardData()
    {
        if (empty($this->getStripeObject()->card))
        {
            return null;
        }

        return [
            'brand' => $this->getStripeObject()->card->brand,
            'last4' => $this->getStripeObject()->card->last4,
            'wallet' => $this->getStripeObject()->card->wallet->type ?? null,
        ];
    }

    public function update(array $data)
    {
        try
        {
            $updatedObject = $this->stripeObjectService->updateObject($this->getId(), $data);
            $this->setObject($updatedObject);
            return $this;
        }
        catch (\Exception $e)
        {
            $this->loggerHelper->logError("Could not update payment method {$this->getId()} of type {$this->getPaymentMethodType()}: " . $e->getMessage());
        }
    }

    public function canBeRedisplayed()
    {
        $type = $this->getPaymentMethodType();
        $unsupportedTypes = ['paypal'];
        return $type && !in_array($type, $unsupportedTypes) && $this->isAttachedToCustomer();
    }

    public function isAlwaysRedisplayed()
    {
        return $this->getStripeObject()->allow_redisplay === 'always';
    }
}