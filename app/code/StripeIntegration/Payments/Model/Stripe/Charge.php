<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Charge
{
    use StripeObjectTrait;

    private $objectSpace = 'charges';
    private $tokenHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Token $tokenHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->tokenHelper = $tokenHelper;
    }

    public function fromChargeId($id, $expandParams = [])
    {
        $id = $this->tokenHelper->cleanToken($id);

        if (!empty($this->getStripeObject()->id) && $this->getStripeObject()->id == $id)
        {
            return $this;
        }

        if (!empty($expandParams))
        {
            $this->setExpandParams($expandParams);
        }

        $this->load($id);
        return $this;
    }

    public function fromObject(\Stripe\Charge $charge)
    {
        $this->setObject($charge);
        return $this;
    }

    public function setExpandParams($params)
    {
        $this->stripeObjectService->setExpandParams($params);

        return $this;
    }

    public function getRiskScore()
    {
        return $this->getStripeObject()->outcome->risk_score ?? null;
    }

    public function getRiskLevel()
    {
        return $this->getStripeObject()->outcome->risk_level ?? null;
    }

    public function isCardPaymentMethod()
    {
        return $this->getStripeObject()->payment_method_details->type == 'card';
    }

    public function getInstallmentsPlan()
    {
        return $this->getStripeObject()->payment_method_details->card->installments->plan ?? null;
    }

    public function getPaymentMethod()
    {
        return $this->getStripeObject()->payment_method ?? null;
    }
}