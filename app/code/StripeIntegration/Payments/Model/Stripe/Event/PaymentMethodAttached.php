<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class PaymentMethodAttached
{
    use StripeObjectTrait;

    private $webhooksHelper;
    private $stripePaymentMethodFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
    }

    public function process($arrEvent, $object): void
    {
        $this->webhooksHelper->deduplicatePaymentMethod($object);

        $stripePaymentMethodModel = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($object['id']);

        if ($stripePaymentMethodModel->canBeRedisplayed() && !$stripePaymentMethodModel->isAlwaysRedisplayed())
        {
            $stripePaymentMethodModel->update(['allow_redisplay' => 'always']);
        }
    }
}