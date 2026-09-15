<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Subscription;

class Payment
{
    public $paymentIntent = null;

    public function setPaymentIntent(?\Stripe\PaymentIntent $paymentIntent)
    {
        $this->paymentIntent = $paymentIntent;
        return $this;
    }

    public function getPaymentIntent(): ?\Stripe\PaymentIntent
    {
        return $this->paymentIntent;
    }
}