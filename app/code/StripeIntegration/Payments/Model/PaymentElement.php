<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Exception\Exception;
use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Exception\OrderPlacedAndPaidException;

class PaymentElement extends \Magento\Framework\Model\AbstractModel
{
    private $paymentIntent = null;
    private $setupIntent = null;
    private $subscription = null;
    private $subscriptionsHelper;
    private $cache;
    private $paymentIntentHelper;
    private $customer;
    private $compare;
    private $paymentIntentModelFactory;
    private $dataHelper;
    private $helper;
    private $config;
    private $stripePaymentIntent;
    private $resourceModel;
    private $orderHelper;
    private $paymentIntentCollection;
    private $quoteHelper;
    private $stripeSetupIntentModelFactory;
    private $checkoutFlow;
    private $orderValidator;
    private $errorHelper;
    private $stripePaymentMethod;
    private $paymentState;
    private $areaCodeHelper;
    private $stripeClient;
    private $invoicePaymentsHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\OrderValidator $orderValidator,
        \StripeIntegration\Payments\Helper\Error $errorHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentModelFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Order\PaymentState $paymentState,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethod $stripePaymentMethod,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \StripeIntegration\Payments\Model\Stripe\Client $stripeClient,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentElement $resourceModel,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection,
        \StripeIntegration\Payments\Model\Stripe\SetupIntentFactory $stripeSetupIntentModelFactory,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->dataHelper = $dataHelper;
        $this->helper = $helper;
        $this->quoteHelper = $quoteHelper;
        $this->compare = $compare;
        $this->paymentIntentModelFactory = $paymentIntentModelFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->cache = $context->getCacheManager();
        $this->config = $config;
        $this->paymentState = $paymentState;
        $this->stripePaymentIntent = $stripePaymentIntent;
        $this->customer = $helper->getCustomerModel();
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->resourceModel = $resourceModel;
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->stripeSetupIntentModelFactory = $stripeSetupIntentModelFactory;
        $this->orderValidator = $orderValidator;
        $this->orderHelper = $orderHelper;
        $this->errorHelper = $errorHelper;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->checkoutFlow = $checkoutFlow;
        $this->stripePaymentMethod = $stripePaymentMethod;
        $this->stripeClient = $stripeClient;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentElement');
    }

    public function updateFromOrder($order)
    {
        try
        {
            if (!$this->helper->isMultiShipping())
            {
                $this->orderValidator->validate($order);
            }
        }
        catch (OrderPlacedAndPaidException $e)
        {
            $this->quoteHelper->deactivateQuoteById($order->getQuoteId());
            return $this->helper->throwError(__("The order has already been placed and paid."));
        }

        $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());

        $this->resourceModel->load($this, $quote->getId(), 'quote_id');

        if ($this->getOrderIncrementId() && $this->getOrderIncrementId() != $order->getIncrementId())
        {
            // Check if this is a duplicate order placement. The old order should have normally been canceled if the cart changed.
            $oldOrder = $this->orderHelper->loadOrderByIncrementId($this->getOrderIncrementId());
            if ($oldOrder && $oldOrder->getState() != "canceled" && !$this->helper->isMultiShipping() && $this->orderHelper->orderAgeLessThan(120, $oldOrder))
            {
                // The case where the old order was not canceled is when the payment failed and the cart contents changed
                $this->setOrderIncrementId($order->getIncrementId())->save();
                if ($order->getGrandTotal() == $oldOrder->getGrandTotal() && $order->getOrderCurrencyCode() == $oldOrder->getOrderCurrencyCode())
                {
                    $comment = __("The customer details have changed, a different checkout flow was selected, or a checkout error occurred. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
                }
                else
                {
                    $comment = __("The cart contents have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
                }

                $this->orderHelper->removeTransactions($oldOrder);
                $this->helper->cancelOrCloseOrder($oldOrder, $comment, true);
            }
        }

        // Update any existing subscriptions
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $params = $paymentIntentModel->getParamsFrom($order);

        if (!empty($params['payment_method']))
        {
            $this->validatePaymentMethod($params['payment_method']);
        }

        $paymentIntent = $setupIntent = null;
        $subscription = $this->subscriptionsHelper->updateSubscriptionFromOrder($order, $this->getSubscriptionId(), $params);

        if ($this->checkoutFlow->isPendingMicrodepositsVerification && $order->getPayment()->getAdditionalInformation("pending_microdeposit_si_id")) {
            $setupIntentId = $order->getPayment()->getAdditionalInformation("pending_microdeposit_si_id");
            $stripeSetupIntentModel = $this->stripeSetupIntentModelFactory->create()->fromSetupIntentId($setupIntentId);
            $setupIntent = $stripeSetupIntentModel->getStripeObject();
        }

        if (!empty($subscription->id))
        {
            $this->updateFromSubscription($subscription);
            $order->getPayment()->setAdditionalInformation("subscription_id", $subscription->id);
            $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromSubscription($subscription);
            $setupIntent = $this->getSetupIntentFromSubscription($subscription);

            $this->subscription = $subscription;
            $this->setSubscriptionId($subscription->id);
        }

        if (!$paymentIntent && !$setupIntent && empty($subscription))
        {
            // Update any existing payment intents
            $paymentIntentModel = $this->paymentIntentCollection->findByQuoteId($quote->getId());
            if (!$paymentIntentModel)
                $paymentIntentModel = $this->paymentIntentModelFactory->create();

            $paymentIntent = $paymentIntentModel->createPaymentIntentFrom($params, $quote, $order);
        }

        // Upon order placement, a customer is always created in Stripe
        if ($this->customer->getStripeId())
        {
            $this->customer->updateFromOrder($order);
            $params['customer'] = $this->customer->getStripeId();
        }

        if ($paymentIntent)
        {
            $this->setupIntent = null;
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($paymentIntent->id);

            $this->paymentIntent = $this->updatePaymentIntentFrom($paymentIntent, $params);
        }
        else if ($setupIntent)
        {
            $this->paymentIntent = null;
            $this->setSetupIntentId($setupIntent->id);
            $this->setPaymentIntentId(null);

            $this->setupIntent = $this->updateSetupIntentFrom($setupIntent, $params);
        }

        $this->setOrderIncrementId($order->getIncrementId());
        $this->setQuoteId($order->getQuoteId());
        $this->resourceModel->save($this);
    }

    private function getSetupIntentFromSubscription($subscription)
    {
        // We get in here when 3DS is required for trial subscriptions or subscriptions with start dates
        $setupIntent = $subscription->pending_setup_intent ?? null;
        if (is_string($setupIntent))
        {
            try
            {
                $setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($setupIntent, []);
            }
            catch (\Exception $e)
            {
                $setupIntent = null;
            }
        }
        return $setupIntent;
    }

    // Validates that the payment method belongs to the current customer.
    public function validatePaymentMethod($paymentMethodId)
    {
        $paymentMethod = $this->stripePaymentMethod->fromPaymentMethodId($paymentMethodId)->getStripeObject();
        if (!empty($paymentMethod->customer) && $this->customer->getStripeId() && $paymentMethod->customer != $this->customer->getStripeId())
        {
            $this->helper->throwError(__("This payment method cannot be used."));
        }
    }

    // There are some cases where an error occurred after placing an order, and somehow the quote was
    // recreated, thus losing the reference to the old Pending order. In those cases, we want to search
    // and find those pending orders and manually cancel them before creating a new one using the same
    // payment intent ID. Having 2 orders with the same payment intent ID is very problematic.
    public function cancelInvalidOrders($currentOrder)
    {
        $transactionId = $this->getPaymentIntentId();

        if (!$transactionId || $this->helper->isMultiShipping())
        {
            return;
        }

        $orders = $this->helper->getOrdersByTransactionId($transactionId);

        $comment = __("The cart contents or customer details have changed, or a checkout crash occurred. The order is canceled because a new one will be placed (#%1).", $currentOrder->getIncrementId());

        foreach ($orders as $order)
        {
            if ($order->getIncrementId() == $currentOrder->getIncrementId())
            {
                continue;
            }

            if ($order->getState() == "canceled")
            {
                continue;
            }

            try
            {
                $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());
                if ($this->helper->isMultiShipping($quote))
                {
                    continue;
                }
                $this->orderHelper->removeTransactions($order);
                $this->helper->cancelOrCloseOrder($order, $comment, true);

                if ($currentOrder->getQuoteId() != $order->getQuoteId())
                {
                    $this->paymentIntentCollection->deleteForQuoteId($order->getQuoteId());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not cancel invalid order: " . $e->getMessage());
            }
        }
    }

    public function updatePaymentIntentFrom($paymentIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($paymentIntent, $params);

        if ($this->compare->isDifferent($paymentIntent, $updateParams))
            return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);

        return $paymentIntent;
    }

    public function updateSetupIntentFrom($setupIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($setupIntent, $params);

        if ($this->compare->isDifferent($setupIntent, $updateParams))
            return $this->config->getStripeClient()->setupIntents->update($setupIntent->id, $updateParams);

        return $setupIntent;
    }

    protected function getFilteredParamsForUpdate($object, $params)
    {
        $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $object);

        if ($this->getSetupIntent() || $this->getSubscription())
        {
            unset($updateParams['amount']); // If we have a subscription, the amount will be incorrect here: Order total - Subscriptions total
        }

        if ($this->getSubscription())
        {
            unset($updateParams['currency']); // The currency of a payment intent that belongs to a subscription invoice cannot be changed
        }

        return ($updateParams ? $updateParams : []);
    }

    public function isOrderPlaced()
    {
        if (!$this->getOrderIncrementId())
        {
            $quote = $this->quoteHelper->getQuote();

            if (!$quote || !$quote->getId())
            {
                return false;
            }

            $this->resourceModel->load($this, $quote->getId(), 'quote_id');
        }

        return (bool)($this->getOrderIncrementId());
    }

    public function getSubscription(): ?\Stripe\Subscription
    {
        return $this->subscription;
    }

    public function getPaymentIntent(): ?\Stripe\PaymentIntent
    {
        return $this->paymentIntent;
    }

    public function getSetupIntent(): ?\Stripe\SetupIntent
    {
        return $this->setupIntent;
    }

    public function fromQuoteId($quoteId)
    {
        $this->resourceModel->load($this, $quoteId, "quote_id");

        if ($this->getPaymentIntentId())
        {
            try
            {
                $paymentIntent = $this->stripePaymentIntent->fromPaymentIntentId($this->getPaymentIntentId())->getStripeObject();
                $this->paymentIntent = $paymentIntent;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setPaymentIntentId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        if ($this->getSetupIntentId())
        {
            try
            {
                $this->setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($this->getSetupIntentId(), []);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setSetupIntentId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        if ($this->getSubscriptionId())
        {
            try
            {
                $this->subscription = $this->config->getStripeClient()->subscriptions->retrieve($this->getSubscriptionId(), []);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setSubscriptionId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        return $this;
    }

    public function isTrialSubscription()
    {
        if ($this->getPaymentIntentId() || $this->getSetupIntentId() || !$this->getSubscription())
        {
            return false;
        }

        return ($this->getSubscription()->status == "trialing");
    }

    public function confirm($order)
    {
        if (!$this->getQuoteId())
        {
            throw new GenericException("Not initialized");
        }

        if ($confirmationObject = $this->getPaymentIntent())
        {
            // Cases: All order placements which require immediate payment, including:
            // - Regular subscriptions
            // - Subscriptions with a future start date, but with first payment required today
            // - Subscriptions with a future start date bought with a regular product, payment for regular product required today
            // - Trial subscriptions bought with a regular product, or with initial fee, and payment required today
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->update($confirmationObject->id, [
                    'description' => $this->orderHelper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the PI on the client side and retries order placement
            if ($this->paymentIntentHelper->isSuccessful($confirmationObject) ||
                $this->paymentIntentHelper->isAsyncProcessing($confirmationObject) ||
                $this->paymentIntentHelper->requiresOfflineAction($confirmationObject))
            {
                // We get here in 2 cases:
                // a) A checkout crash in a sales_order_place_after observer may have forced the customer to place the order twice
                // b) Non-PaymentElement 3D Secure authentications, which were done on the client side, i.e. GraphQL, Wallet button etc
                $this->paymentState->setConfirmedPaymentIntent($confirmationObject);
                return $confirmationObject;
            }

            $confirmParams = $this->paymentIntentHelper->getConfirmParams($order, $confirmationObject);
            if ($this->areaCodeHelper->isAdmin())
            {
                $hasSubscriptions = $this->quoteHelper->hasSubscriptions();
                $isPaymentMethodBeingSaved = !empty($confirmParams["payment_method_options"]["card"]["setup_future_usage"]);
                $canUseOffSession = !$hasSubscriptions && !$isPaymentMethodBeingSaved;
                $result = $this->stripeClient->adminConfirmPaymentIntent($confirmationObject->id, $confirmParams, $canUseOffSession);
            }
            else
            {
                $result = $this->config->getStripeClient()->paymentIntents->confirm($confirmationObject->id, $confirmParams);
            }
            $this->paymentIntent = $result;
            $this->paymentState->setConfirmedPaymentIntent($result);
        }
        else if ($confirmationObject = $this->getSetupIntent())
        {
            // Cases:
            // - Buying a single subscription with a future start date, new or saved payment method is used, 3DS required or not
            // - Buying a single trial subscription, new or saved payment method is used, 3DS required or not
            // - Microdeposits verification required for ACH bank account payment method
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->setupIntent = $this->config->getStripeClient()->setupIntents->update($confirmationObject->id, [
                    'description' => $this->orderHelper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the SI on the client side and retries order placement
            if ($confirmationObject->status == "succeeded")
            {
                return $confirmationObject;
            }

            if ($this->checkoutFlow->isPendingMicrodepositsVerification)
            {
                // We wont attempt a confirmation if microdeposit verification is required.
                return $confirmationObject;
            }

            $confirmParams = $this->paymentIntentHelper->getConfirmParams($order, $confirmationObject);
            $confirmParams = $this->dataHelper->convertToSetupIntentConfirmParams($confirmParams);

            try
            {
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->errorHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
        }
        else if ($confirmationObject = $this->getSubscription())
        {
            // Case: A subscription is being re-activated, but it no longer has a payment method, so one is collected via the checkout page
            $confirmationObject = $this->updateSubscriptionFromOrder($confirmationObject, $order);

            if ($confirmationObject->status == "trialing")
            {
                // Case: ...and the subscription was reactivated with a trial period, likely because the old trial end
                // is still in the future. No payment is required today.
                return $confirmationObject;
            }
            else if ($confirmationObject->status == "active")
            {
                // Case: ...and the subscription is a regular one
                $invoiceId = null;
                if (!empty($confirmationObject->latest_invoice))
                {
                    // Case: ...and a payment is required today.
                    // Note: This code is kept due to logic prior to v4.5.0. Unknown if it hits in any cases after v4.5.0, possibly dead code.
                    if (is_string($confirmationObject->latest_invoice))
                    {
                        $invoiceId = $confirmationObject->latest_invoice;
                    }
                    else
                    {
                        $invoiceId = $confirmationObject->latest_invoice->id;
                    }

                    $this->paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromInvoiceId($invoiceId);

                    /** @var \Stripe\Subscription $confirmationObject */
                    if (!$this->paymentIntent && !empty($confirmationObject->default_payment_method))
                    {
                        // A subscription is set up with a future start date, and payment is required today.
                        $this->config->getStripeClient()->invoices->pay($invoiceId);
                        $this->paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromInvoiceId($invoiceId);
                        if ($this->paymentIntentHelper->requiresOnlineAction($this->paymentIntent))
                        {
                            return $this->confirm($order);
                        }
                        return $this->paymentIntent;
                    }
                    else if ($this->paymentIntentHelper->requiresOnlineAction($this->paymentIntent))
                    {
                        return $this->confirm($order);
                    }
                }

                // Case: ...and the subscription was reactivated before its last period ended, so no payment was required today,
                // because of billing_cycle_anchor on the subscription creation params
                return $confirmationObject;
            }
            else
            {
                // Should never get here
                // @codeCoverageIgnoreStart
                throw new GenericException("Could not set up subscription.");
                // @codeCoverageIgnoreEnd
            }
        }
        else
        {
            // Should never get here
            // @codeCoverageIgnoreStart
            throw new GenericException("Could not confirm payment.");
            // @codeCoverageIgnoreEnd
        }

        return $result;
    }

    private function updateSubscriptionFromOrder(\Stripe\Subscription $subscription, $order): \Stripe\Subscription
    {
        $updateParams = [];

        if (empty($subscription->metadata->{'Order #'}))
        {
            $updateParams = [
                'description' => $this->orderHelper->getOrderDescription($order),
                'metadata' => $this->config->getMetadata($order)
            ];
        }

        if (($subscription->status == "trialing" || $subscription->status == "active") &&
            empty($subscription->default_payment_method))
        {
            $paymentMethodId = $this->orderHelper->getPaymentMethodId($order);
            if ($paymentMethodId)
            {
                try
                {
                    // Attach the payment method to the customer
                    $this->config->getStripeClient()->paymentMethods->attach($paymentMethodId, [
                        'customer' => $this->customer->getStripeId()
                    ]);

                    $updateParams['default_payment_method'] = $paymentMethodId;
                }
                catch (\Exception $e)
                {
                    $this->helper->logError("Could attach payment method to customer: " . $e->getMessage());
                }
            }
        }

        if (!empty($updateParams))
        {
            $subscription = $this->subscription = $this->config->getStripeClient()->subscriptions->update($subscription->id, $updateParams);
        }

        return $subscription;
    }

    public function requiresConfirmation()
    {
        if (!$this->paymentIntent && !$this->setupIntent)
        {
            if ($this->getPaymentIntentId())
            {
                $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($this->getPaymentIntentId(), []);
            }
            else if ($this->getSetupIntentId())
            {
                $this->setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($this->getSetupIntentId(), []);
            }
        }

        if ($this->paymentIntent && $this->paymentIntent->status == "requires_confirmation")
        {
            return true;
        }

        if ($this->setupIntent && $this->setupIntent->status == "requires_confirmation")
        {
            return true;
        }

        return false;
    }

    public function hasPaymentMethodChanged()
    {

        if (!$this->paymentIntent && !$this->setupIntent)
        {
            if ($this->getPaymentIntentId())
            {
                $obj = $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($this->getPaymentIntentId(), []);
            }
            else if ($this->getSetupIntentId())
            {
                $obj = $this->setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($this->getSetupIntentId(), []);
            }
            else
            {
                return false;
            }
        }

        if (!$this->getOrderIncrementId())
        {
            return false;
        }

        $order = $this->orderHelper->loadOrderByIncrementId($this->getOrderIncrementId());
        if (!$order)
        {
            return false;
        }

        $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
        if (empty($paymentMethodId))
        {
            return false;
        }

        if ($this->paymentIntent)
        {
            if (empty($this->paymentIntent->payment_method) || $this->paymentIntent->payment_method != $paymentMethodId)
            {
                return true;
            }
        }

        if ($this->setupIntent)
        {
            if (empty($this->setupIntent->payment_method) || $this->setupIntent->payment_method != $paymentMethodId)
            {
                return true;
            }
        }

        return false;
    }

    protected function updateFromSubscription(?\Stripe\Subscription $subscription)
    {
        if (empty($subscription->id))
            return;

        $this->setSubscriptionId($subscription->id);

        $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromSubscription($subscription);
        $setupIntent = $this->getSetupIntentFromSubscription($subscription);
        if (!empty($paymentIntent->id))
        {
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($paymentIntent->id);
            $this->setupIntent = null;
            $this->paymentIntent = $paymentIntent;
        }
        else if (!empty($setupIntent->id))
        {
            // Trial subscription or subscription with future start date
            $this->setSetupIntentId($setupIntent->id);
            $this->setPaymentIntentId(null);
            $this->setupIntent = $setupIntent;
            $this->paymentIntent = null;
        }

        $this->resourceModel->save($this);
    }
}
