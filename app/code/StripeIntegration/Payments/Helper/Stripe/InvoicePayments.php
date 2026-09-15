<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper\Stripe;

class InvoicePayments
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->config = $config;
    }

    public function listByInvoiceId(string $invoiceId): \Stripe\Collection
    {
        return $this->config->getStripeClient()->invoicePayments->all([
            'invoice' => $invoiceId,
        ]);
    }

    public function listByPaymentIntentId(string $paymentIntentId): \Stripe\Collection
    {
        return $this->config->getStripeClient()->invoicePayments->all([
            'payment' => [
                'type' => 'payment_intent',
                'payment_intent' => $paymentIntentId,
            ]
        ]);
    }

    public function getInvoiceFromPaymentIntentId(string $paymentIntentId): ?\Stripe\Invoice
    {
        $invoicePayments = $this->config->getStripeClient()->invoicePayments->all([
            'payment' => [
                'type' => 'payment_intent',
                'payment_intent' => $paymentIntentId,
            ],
            'expand' => ['data.invoice']
        ]);

        // Will always be associated with either zero or a single invoice
        foreach ($invoicePayments->data as $invoicePayment)
        {
            return $invoicePayment->invoice;
        }

        return null;
    }

    public function getLatestPaymentIntentFromInvoiceId(string $invoiceId, $params = []): ?\Stripe\PaymentIntent
    {
        $paymentIntentId = $this->getLatestPaymentIntentIdFromInvoiceId($invoiceId);

        if ($paymentIntentId)
        {
            return $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, $params);
        }

        return null;
    }

    public function getLatestPaymentIntentIdFromInvoiceId(string $invoiceId): ?string
    {
        $invoicePayments = $this->listByInvoiceId($invoiceId);

        $latest = null;
        foreach ($invoicePayments->autoPagingIterator() as $invoicePayment)
        {
            if (empty($invoicePayment->payment) || empty($invoicePayment->payment->payment_intent))
                continue;

            if ($latest === null || $invoicePayment->created > $latest->created)
            {
                $latest = $invoicePayment;
            }
        }

        if ($latest)
        {
            return $latest->payment->payment_intent;
        }

        return null;
    }

    public function getLatestPaymentIntentIdFromSubscription(\Stripe\Subscription $subscription): ?string
    {

        if (empty($subscription->latest_invoice))
        {
            return null;
        }

        if (isset($subscription->latest_invoice->id))
        {
            $invoiceId = $subscription->latest_invoice->id;
        }
        else
        {
            $invoiceId = $subscription->latest_invoice;
        }

        return $this->getLatestPaymentIntentIdFromInvoiceId($invoiceId);
    }

    public function getLatestPaymentIntentFromSubscription(\Stripe\Subscription $subscription): ?\Stripe\PaymentIntent
    {
        $paymentIntentId = $this->getLatestPaymentIntentIdFromSubscription($subscription);

        if ($paymentIntentId)
        {
            return $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        }

        return null;
    }
}