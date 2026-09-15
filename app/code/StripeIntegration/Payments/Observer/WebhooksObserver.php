<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Model\Stripe\Event;

class WebhooksObserver implements ObserverInterface
{
    // Event processors
    private $invoicePaymentSucceeded;
    private $checkoutSessionCompleted;
    private $chargeCaptured;
    private $checkoutSessionExpired;
    private $paymentIntentProcessing;
    private $reviewClosed;
    private $customerSubscriptionUpdated;
    private $customerSubscriptionCreated;
    private $customerSubscriptionDeleted;
    private $invoiceVoided;
    private $chargeRefunded;
    private $paymentIntentCanceled;
    private $paymentIntentPaymentFailed;
    private $paymentIntentPartiallyFunded;
    private $setupIntentSucceeded;
    private $chargeSucceeded;
    private $invoicePaid;
    private $invoiceUpcoming;
    private $chargeDisputeCreated;
    private $chargeDisputeClosed;
    private $paymentMethodAttached;
    private $refundUpdated;
    private $refundFailed;

    public function __construct(
        Event\InvoicePaymentSucceeded $invoicePaymentSucceeded,
        Event\CheckoutSessionCompleted $checkoutSessionCompleted,
        Event\ChargeCaptured $chargeCaptured,
        Event\CheckoutSessionExpired $checkoutSessionExpired,
        Event\PaymentIntentProcessing $paymentIntentProcessing,
        Event\ReviewClosed $reviewClosed,
        Event\CustomerSubscriptionUpdated $customerSubscriptionUpdated,
        Event\CustomerSubscriptionCreated $customerSubscriptionCreated,
        Event\CustomerSubscriptionDeleted $customerSubscriptionDeleted,
        Event\InvoiceVoided $invoiceVoided,
        Event\ChargeRefunded $chargeRefunded,
        Event\PaymentIntentCanceled $paymentIntentCanceled,
        Event\PaymentIntentPaymentFailed $paymentIntentPaymentFailed,
        Event\PaymentIntentPartiallyFunded $paymentIntentPartiallyFunded,
        Event\SetupIntentSucceeded $setupIntentSucceeded,
        Event\ChargeSucceeded $chargeSucceeded,
        Event\InvoicePaid $invoicePaid,
        Event\InvoiceUpcoming $invoiceUpcoming,
        Event\ChargeDisputeCreated $chargeDisputeCreated,
        Event\ChargeDisputeClosed $chargeDisputeClosed,
        Event\PaymentMethodAttached $paymentMethodAttached,
        Event\RefundUpdated $refundUpdated,
        Event\RefundFailed $refundFailed
    )
    {
        $this->invoicePaymentSucceeded = $invoicePaymentSucceeded;
        $this->checkoutSessionCompleted = $checkoutSessionCompleted;
        $this->chargeCaptured = $chargeCaptured;
        $this->checkoutSessionExpired = $checkoutSessionExpired;
        $this->paymentIntentProcessing = $paymentIntentProcessing;
        $this->reviewClosed = $reviewClosed;
        $this->customerSubscriptionUpdated = $customerSubscriptionUpdated;
        $this->customerSubscriptionCreated = $customerSubscriptionCreated;
        $this->customerSubscriptionDeleted = $customerSubscriptionDeleted;
        $this->invoiceVoided = $invoiceVoided;
        $this->chargeRefunded = $chargeRefunded;
        $this->paymentIntentCanceled = $paymentIntentCanceled;
        $this->paymentIntentPaymentFailed = $paymentIntentPaymentFailed;
        $this->paymentIntentPartiallyFunded = $paymentIntentPartiallyFunded;
        $this->setupIntentSucceeded = $setupIntentSucceeded;
        $this->chargeSucceeded = $chargeSucceeded;
        $this->invoicePaid = $invoicePaid;
        $this->invoiceUpcoming = $invoiceUpcoming;
        $this->chargeDisputeCreated = $chargeDisputeCreated;
        $this->chargeDisputeClosed = $chargeDisputeClosed;
        $this->paymentMethodAttached = $paymentMethodAttached;
        $this->refundUpdated = $refundUpdated;
        $this->refundFailed = $refundFailed;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        $arrEvent = $observer->getData('arrEvent');
        $stdEvent = $observer->getData('stdEvent');
        $object = $observer->getData('object');

        switch ($eventName)
        {
            case 'stripe_payments_webhook_checkout_session_expired':

                $this->checkoutSessionExpired->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_processing':

                $this->paymentIntentProcessing->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_checkout_session_completed':

                // Called when placing a trial subscription order with Stripe Checkout
                // Performs order post processing after a successful setup intent
                $this->checkoutSessionCompleted->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_captured':

                // Creates an invoice for an order when the payment is captured from the Stripe dashboard
                $this->chargeCaptured->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_review_closed':

                $this->reviewClosed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_customer_subscription_updated':

                $this->customerSubscriptionUpdated->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_customer_subscription_created':

                $this->customerSubscriptionCreated->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_customer_subscription_deleted':

                $this->customerSubscriptionDeleted->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_invoice_upcoming':

                $this->invoiceUpcoming->process($object);
                break;

            case 'stripe_payments_webhook_invoice_voided':
            case 'stripe_payments_webhook_invoice_marked_uncollectible':

                $this->invoiceVoided->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_refunded':

                $this->chargeRefunded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_setup_intent_canceled':
            case 'stripe_payments_webhook_payment_intent_canceled':

                $this->paymentIntentCanceled->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_succeeded':

                break;

            case 'stripe_payments_webhook_setup_intent_setup_failed':
            case 'stripe_payments_webhook_payment_intent_payment_failed':

                $this->paymentIntentPaymentFailed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_partially_funded':

                $this->paymentIntentPartiallyFunded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_method_attached':

                $this->paymentMethodAttached->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_setup_intent_succeeded':

                $this->setupIntentSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_succeeded':

                $this->chargeSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_dispute_created':

                $this->chargeDisputeCreated->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_dispute_closed':

                $this->chargeDisputeClosed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_refund_updated':

                $this->refundUpdated->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_refund_failed':

                $this->refundFailed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_payment_succeeded':

                // Recurring subscription payments
                $this->invoicePaymentSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_paid':

                $this->invoicePaid->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_payment_failed':
                //$this->paymentFailed($event);
                break;

            default:
                # code...
                break;
        }
    }
}
