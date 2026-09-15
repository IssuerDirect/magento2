<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper\Stripe;

class Charges
{
    private $config;
    private $dataHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Data $dataHelper
    )
    {
        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    public function getLatestChargeFromInvoiceId(string $invoiceId): ?\Stripe\Charge
    {
        $invoicePayments = $this->config->getStripeClient()->invoicePayments->all([
            'invoice' => $invoiceId,
            'expand' => ['data.payment.charge', 'data.payment.payment_intent.latest_charge']
        ]);

        /** @var \Stripe\InvoicePayment $latestInvoicePayment */
        $latestInvoicePayment = $this->dataHelper->getLatestStripeObject($invoicePayments->autoPagingIterator());

        if (!$latestInvoicePayment || empty($latestInvoicePayment->payment->type))
        {
            return null;
        }
        else if ($latestInvoicePayment->payment->type == 'charge')
        {
            return $latestInvoicePayment->payment->charge;
        }
        else if ($latestInvoicePayment->payment->type == 'payment_intent')
        {
            return $latestInvoicePayment->payment->payment_intent->latest_charge;
        }
        else
        {
            return null;
        }
    }
}