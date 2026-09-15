<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class InvoicePaymentSucceeded
{
    use StripeObjectTrait;
    private $recurringOrderHelper;
    private $checkoutSessionHelper;
    private $paymentIntentFactory;
    private $subscriptionReactivationCollection;
    private $webhooksHelper;
    private $config;
    private $helper;
    private $subscriptionsHelper;
    private $orderHelper;
    private $subscriptionCollection;
    private $invoicePaymentsHelper;
    private $webhookEventCollectionFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\RecurringOrder $recurringOrderHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Stripe\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\ResourceModel\SubscriptionReactivation\Collection $subscriptionReactivationCollection,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription\Collection $subscriptionCollection,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->recurringOrderHelper = $recurringOrderHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->orderHelper = $orderHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->subscriptionReactivationCollection = $subscriptionReactivationCollection;
        $this->webhooksHelper = $webhooksHelper;
        $this->config = $config;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->subscriptionCollection = $subscriptionCollection;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
    }

    public function process($arrEvent, $object)
    {
        try
        {
            $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        }
        catch (\StripeIntegration\Payments\Exception\SubscriptionUpdatedException $e)
        {
            try
            {
                if ($object['billing_reason'] == "subscription_cycle")
                {
                    return $this->recurringOrderHelper->createFromQuoteId($e->getQuoteId(), $object['id']);
                }
                else /* if ($object['billing_reason'] == "subscription_update") */
                {
                    // At the very first subscription update, do not create a recurring order.
                    return;
                }
            }
            catch (\Throwable $e)
            {
                $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                throw $e;
            }
        }

        if (empty($order->getPayment()))
            throw new WebhookException("Order #%1 does not have any associated payment details.", $order->getIncrementId());

        $paymentMethod = $order->getPayment()->getMethod();
        $invoiceId = $object['id'];
        $invoiceParams = [
            'expand' => [
                'lines.data.price.product',
                'parent.subscription_details.subscription'
            ]
        ];
        /** @var \Stripe\StripeObject $invoice */
        $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);

        $subscriptionId = $invoice->parent->subscription_details->subscription->id ?? null;

        if (empty($subscriptionId) || empty($object["billing_reason"]))
        {
            return; // This is not a subscription invoice, it might have been created with Stripe Billing or Bank Transfers from the admin area
        }

        $subscriptionModel = $this->subscriptionCollection->getBySubscriptionId($subscriptionId);

        switch ($object["billing_reason"])
        {
            case "subscription_cycle":
                $isNewSubscriptionOrder = false;
                break;

            case "subscription_create":
                $isNewSubscriptionOrder = true;
                break;

            case "manual":
            case "upcoming":
                // Not a subscription
                return;

            case "subscription_update":
            case "subscription_threshold":
                $isNewSubscriptionOrder = $subscriptionModel->isNewSubscription();
                break;

            default:
                throw new WebhookException(__("Unknown billing reason: %1", $object["billing_reason"]));
        }

        $isSubscriptionReactivation = $this->isSubscriptionReactivation($order);

        /** @var \Stripe\Subscription $subscription */
        $subscription = $invoice->parent->subscription_details->subscription;
        $subscriptionModel->initFrom($subscription, $order)->save();

        switch ($paymentMethod)
        {
            case 'stripe_payments':
            case 'stripe_payments_express':

                $updateParams = [];

                $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromSubscription($subscription);
                if (empty($subscription->default_payment_method) && !empty($paymentIntent->payment_method))
                {
                    $paymentMethod = $this->config->getStripeClient()->paymentMethods->retrieve($paymentIntent->payment_method);
                    if (!empty($paymentMethod->customer) && !empty($subscription->customer) && $paymentMethod->customer == $subscription->customer)
                    {
                        $updateParams["default_payment_method"] = $paymentIntent->payment_method;
                    }
                }

                if (empty($subscription->metadata->{"Order #"}))
                    $updateParams["metadata"] = ["Order #" => $order->getIncrementId()];

                if (!empty($updateParams))
                    $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

                if ($paymentIntent && strpos($paymentIntent->description, $order->getIncrementId()) === false)
                {
                    $paymentIntent = $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, [
                        "description" => $this->orderHelper->getOrderDescription($order)
                    ]);
                }

                if (!$isNewSubscriptionOrder || $isSubscriptionReactivation)
                {
                    try
                    {
                        // This is a recurring payment, so create a brand new order based on the original one
                        $this->recurringOrderHelper->reOrder($order, $invoice);

                    }
                    catch (\Throwable $e)
                    {
                        $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                        throw $e;
                    }
                }

                break;

            case 'stripe_payments_checkout':

                if ($isNewSubscriptionOrder)
                {
                    $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromInvoiceId($invoiceId);
                    if (!empty($paymentIntent))
                    {
                        // With Stripe Checkout, the Payment Intent description and metadata can be set only
                        // after the payment intent is confirmed and the subscription is created.
                        $params = $this->paymentIntentFactory->create()->getParamsFrom($order);
                        $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params, $paymentIntent, $filter = ["description", "metadata"]);
                        $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);

                        $events = $this->webhookEventCollectionFactory->create()->getSkippedChargeSucceededEvents($paymentIntent->id);
                        foreach ($events as $eventModel)
                        {
                            // Hits with Trial Subscription + Simple Product, paid with a redirect-based PM like Revolut
                            // Event originally skipped because it had no metadata to link it to the order
                            try
                            {
                                $eventModel->process($this->config->getStripeClient());
                            }
                            catch (\Exception $e)
                            {
                                $eventModel->refresh()->setLastErrorFromException($e);
                            }

                            $eventModel->save();
                        }
                    }
                    else if ($this->subscriptionsHelper->hasOnlyTrialSubscriptionsIn($order->getAllItems()))
                    {
                        // No charge.succeeded event will arrive, so ready the order for fulfillment here.
                        if (!$order->getEmailSent())
                        {
                            $this->orderHelper->sendNewOrderEmailFor($order, true);
                        }
                        if ($order->getInvoiceCollection()->getSize() < 1)
                        {
                            $this->helper->invoiceOrder($order, null, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                        }
                        $this->helper->setProcessingState($order, __("Trial subscription started."));
                        $this->orderHelper->saveOrder($order);
                    }
                }
                else // Is recurring subscription order
                {
                    try
                    {
                        // This is a recurring payment, so create a brand new order based on the original one
                        $this->recurringOrderHelper->reOrder($order, $invoice);
                    }
                    catch (\Throwable $e)
                    {
                        $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                        throw $e;
                    }
                }

                break;

            default:
                # code...
                break;
        }

        if ($isSubscriptionReactivation)
        {
            $this->subscriptionReactivationCollection->deleteByOrderIncrementId($order->getIncrementId());
        }
    }

    private function isSubscriptionReactivation($order)
    {
        $collection = $this->subscriptionReactivationCollection->getByOrderIncrementId($order->getIncrementId());

        foreach ($collection as $reactivation)
        {
            return true;
        }

        return false;
    }
}
