<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model;

class PaymentIntent extends \Magento\Framework\Model\AbstractModel
{
    private $paymentIntent = null;

    public const SUCCEEDED = "succeeded";
    public const AUTHORIZED = "requires_capture";
    public const CAPTURE_METHOD_MANUAL = "manual";
    public const CAPTURE_METHOD_AUTOMATIC = "automatic";
    public const REQUIRES_ACTION = "requires_action";
    public const CANCELED = "canceled";
    public const AUTHENTICATION_FAILURE = "payment_intent_authentication_failure";

    private $compare;
    private $addressHelper;
    private $customer;
    private $subscriptionsHelper;
    private $paymentIntentHelper;
    private $helper;
    private $config;
    private $paymentIntentCollection;
    private $resourceModel;
    private $orderHelper;
    private $convert;
    private $paymentMethodTypesHelper;
    private $tokenHelper;
    private $paymentLineItemsModelFactory;
    private $redirectInvoiceHelper;
    private $paymentState;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\PaymentMethodTypes $paymentMethodTypesHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\RedirectInvoice $redirectInvoiceHelper,
        \StripeIntegration\Payments\Model\Order\PaymentState $paymentState,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Payment\LineItemsFactory $paymentLineItemsModelFactory,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent $resourceModel,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->helper = $helper;
        $this->compare = $compare;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->convert = $convert;
        $this->config = $config;
        $this->paymentMethodTypesHelper = $paymentMethodTypesHelper;
        $this->customer = $helper->getCustomerModel();
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->resourceModel = $resourceModel;
        $this->orderHelper = $orderHelper;
        $this->tokenHelper = $tokenHelper;
        $this->paymentLineItemsModelFactory = $paymentLineItemsModelFactory;
        $this->redirectInvoiceHelper = $redirectInvoiceHelper;
        $this->paymentState = $paymentState;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentIntent');
    }

    // If we already created any payment intents for this quote, load them
    private function invalidateFrom($params, $quote, $order)
    {
        if (!$quote || !$quote->getId() || !$this->getPiId())
            return null;

        $quoteId = $quote->getId();

        $paymentIntent = null;

        try
        {
            $paymentIntent = $this->loadPaymentIntent($this->getPiId(), $order);
        }
        catch (\Exception $e)
        {
            // If the Stripe API keys or the Mode was changed mid-checkout-session, we may get here
            $this->destroy();
            return null;
        }

        if ($this->isInvalid($params, $quote, $order, $paymentIntent))
        {
            $this->destroy($paymentIntent);
            return null;
        }

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateFrom($paymentIntent, $params, $quote, $order);
        }

        if ($paymentIntent)
        {
            $this->updateModelFrom($quote, $paymentIntent, $order);
        }
        else
        {
            $this->destroy();
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function canCancel($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        if (empty($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->isAsyncProcessing($paymentIntent))
        {
            return false;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return false;
        }

        return true;
    }

    private function loadPaymentIntent($paymentIntentId, $order = null)
    {
        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['amount_details.line_items']]);

        // If the PI has a customer attached, load the customer locally as well
        if (!empty($paymentIntent->customer))
        {
            $customer = $this->helper->getCustomerModelByStripeId($paymentIntent->customer);
            if ($customer)
                $this->customer = $customer;

            if (!$this->customer->getStripeId())
            {
                $this->customer->createStripeCustomer($order, ["id" => $paymentIntent->customer]);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function createPaymentIntentFrom($params, $quote, $order = null)
    {
        if (empty($params['amount']) || $params['amount'] <= 0)
            return null;

        $paymentIntent = $this->invalidateFrom($params, $quote, $order);

        if (!$paymentIntent)
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $this->updateModelFrom($quote, $paymentIntent, $order);

            if ($order)
            {
                $payment = $order->getPayment();
                $payment->setAdditionalInformation("payment_intent_id", $paymentIntent->id);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    private function updateModelFrom($quote, $paymentIntent, $order = null)
    {
        if ($order)
        {
            $quoteId = $order->getQuoteId();
            $customerEmail = $order->getCustomerEmail();
        }
        else
        {
            $quoteId = $quote->getId();
            $customerEmail = $quote->getCustomerEmail();
        }

        if (!$this->getQuoteId())
        {
            $this->resourceModel->load($this, $quoteId, 'quote_id');
        }

        $oldPiId = $this->getPiId();

        $this->setPiId($paymentIntent->id);
        $this->setQuoteId($quoteId);
        $this->setCustomerEmail($customerEmail);

        if ($order)
        {
            if ($order->getIncrementId())
                $this->setOrderIncrementId($order->getIncrementId());

            if ($order->getId())
                $this->setOrderId($order->getId());

            $customerId = $order->getCustomerId();
            if (!empty($customerId))
                $this->setCustomerId($customerId);
            else
                $this->setCustomerId(null);

            if ($order->getPayment()->getAdditionalInformation("confirmation_token"))
            {
                $this->setPmId($order->getPayment()->getAdditionalInformation("confirmation_token"));
            }
            else if ($order->getPayment()->getAdditionalInformation("token"))
            {
                $this->setPmId($order->getPayment()->getAdditionalInformation("token"));
            }
            else
            {
                $this->setPmId(null);
            }
        }
        else
        {
            $this->setOrderId(null);
            $this->setOrderIncrementId(null);
            $this->setCustomerId(null);
            $this->setPmId(null);
        }

        $this->resourceModel->save($this);

        // For some reason, saving the model creates a new entry instead of replacing the old one
        // so we manually remove the old one
        if ($oldPiId && $oldPiId != $this->getPiId() && $quoteId)
        {
            $this->paymentIntentCollection->deleteForQuoteIdAndPiId($quoteId, $oldPiId);
        }
    }

    public function getMultishippingParamsFrom($quote, $orders)
    {
        $amount = 0;
        $currency = null;
        $orderIncrementIds = [];

        foreach ($orders as $order)
        {
            $amount += round(floatval($order->getGrandTotal()), 2);
            $currency = $order->getOrderCurrencyCode();
            $orderIncrementIds[] = $order->getIncrementId();
        }

        $params['amount'] = $this->convert->magentoAmountToStripeAmount($amount, $currency);
        $params['currency'] = strtolower($currency);
        $params['capture_method'] = $this->config->getCaptureMethod();

        if ($usage = $this->config->getSetupFutureUsage($quote))
            $params['setup_future_usage'] = $usage;

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists();
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        $params["description"] = $this->helper->getMultishippingOrdersDescription($quote, $orders);
        $params["metadata"] = $this->config->getMultishippingMetadata($quote, $orders);

        $customerEmail = $quote->getCustomerEmail();
        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        $params['automatic_payment_methods'] = [ "enabled" => true ];

        return $params;
    }

    public function getParamsFrom(\Magento\Sales\Api\Data\OrderInterface $order)
    {
        $amount = $order->getGrandTotal();
        $currency = $order->getOrderCurrencyCode();

        $paymentMethodTypes = $this->paymentMethodTypesHelper->getPaymentMethodTypes();
        if ($paymentMethodTypes)
        {
            // Legacy Express Checkout Element code, no longer used, but kept in case customizations are needed
            $params['payment_method_types'] = $paymentMethodTypes;
        }
        else
        {
            $params['automatic_payment_methods'] = [ 'enabled' => 'true' ];
        }

        $params['amount'] = $this->convert->magentoAmountToStripeAmount($amount, $currency);
        $params['currency'] = strtolower($currency);

        $statementDescriptor = $this->config->getStatementDescriptor();
        if (!empty($statementDescriptor))
            $params["statement_descriptor_suffix"] = $statementDescriptor;

        if (!$this->customer->getStripeId())
        {
            if ($this->helper->isCustomerLoggedIn() || $this->config->alwaysSavePaymentMethod() || $this->isAdminSavingPaymentMethod($order))
            {
                $this->customer->createStripeCustomerIfNotExists(false, $order);
            }
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        $params["description"] = $this->orderHelper->getOrderDescription($order);
        $params["metadata"] = $this->config->getMetadata($order);

        // Add subscription initial fees to the amount, or remove any trial subscription amounts
        $subscriptionsTotal = $this->getSubscriptionsAmount($order);
        $stripeSubscriptionsTotal = $this->convert->magentoAmountToStripeAmount($subscriptionsTotal, $currency);
        $params['amount'] -= $stripeSubscriptionsTotal;

        $shippingAddress = $this->addressHelper->getShippingAddressFromOrder($order);
        if ($shippingAddress)
        {
            $params['shipping'] = $shippingAddress;
        }

        $customerEmail = $order->getCustomerEmail();

        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        if ($this->config->isPaymentLineItemsEnabled())
        {
            $lineItemsParams = $this->paymentLineItemsModelFactory->create()->fromOrder($order)->getPaymentIntentFormat();

            if (!empty($lineItemsParams))
            {
                $params = array_merge($params, $lineItemsParams);
            }
        }

        return $params;
    }

    private function isAdminSavingPaymentMethod($order)
    {
        return $order->getPayment()->getAdditionalInformation("save_payment_method");
    }

    // Returns the subscription total that is chargeable immediately
    protected function getSubscriptionsAmount(\Magento\Sales\Api\Data\OrderInterface $order)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return 0;

        $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);

        $subscriptionsTotal = 0;
        if (!empty($subscription['profile']))
        {
            $subscriptionsTotal += $this->subscriptionsHelper->getSubscriptionTotalFromProfile($subscription['profile']);
            $subscriptionsTotal -= $subscription['profile']['deducted_order_amount']; // Exclude future subscription amounts
            $subscriptionsTotal = round($subscriptionsTotal, 4); // Removes floating point errors
        }

        if ($subscriptionsTotal < 0)
        {
            $this->helper->logError("Cannot set up subscription because the subscription total is negative: " . $subscriptionsTotal);
            $this->helper->throwError(__("The subscription could not be set up. Please contact support."));
        }

        return $subscriptionsTotal;
    }

    // Returns true if the payment intent:
    // a) is in a state that cannot be used for a purchase
    // b) a parameter that cannot be updated has changed
    public function isInvalid($params, $quote, $order, $paymentIntent)
    {
        if ($params['amount'] <= 0)
        {
            return true;
        }

        if (empty($paymentIntent))
        {
            return true;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return true;
        }

        // You cannot modify `customer` on a PaymentIntent once it already has been set. To fulfill a payment with a different Customer,
        // cancel this PaymentIntent and create a new one.
        if (!empty($paymentIntent->customer))
        {
            if (empty($params["customer"]) || $paymentIntent->customer != $params["customer"])
            {
                return true;
            }
        }

        // You passed an empty string for 'shipping'. We assume empty values are an attempt to unset a parameter; however 'shipping'
        // cannot be unset. You should remove 'shipping' from your request or supply a non-empty value.
        if (!empty($paymentIntent->shipping))
        {
            if (isset($params["shipping"]) && empty($params["shipping"]))
            {
                return true;
            }
        }

        // amount_details and payment_details cannot be unset via the Stripe API.
        if (!empty($paymentIntent->amount_details->line_items->data) && empty($params['amount_details']))
        {
            return true;
        }

        if (!empty($paymentIntent->payment_details) && empty($params['payment_details']))
        {
            return true;
        }

        // Case where the user navigates to the standard checkout, the PI is created,
        // and then the customer switches to multishipping checkout.
        if ($this->helper->isMultiShipping())
        {
            if (!empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }
        // ...and vice versa
        else
        {
            if (empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent) ||
            $this->paymentIntentHelper->isAsyncProcessing($paymentIntent) ||
            $this->paymentIntentHelper->requiresOfflineAction($paymentIntent)
            )
        {
            $expectedValues = [
                'amount' => $params['amount'],
                'currency' => $params['currency']
            ];

            if ($this->compare->isDifferent($paymentIntent, $expectedValues))
            {
                $this->helper->logError("PaymentIntent " . $paymentIntent->id . " was successful, but is in an invalid state: " . $this->compare->lastReason);
                return true;
            }
        }

        return false;
    }

    public function updateFrom($paymentIntent, $params, $quote, $order, $cache = true)
    {
        if (empty($quote))
            return null;

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateStripeObject($paymentIntent, $params);

            if ($cache)
                $this->updateModelFrom($quote, $paymentIntent, $order);
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function updateStripeObject($paymentIntent, $params)
    {
        $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $paymentIntent);

        return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);
    }

    public function destroy($paymentIntentToCancel = null)
    {
        if ($paymentIntentToCancel && $this->canCancel($paymentIntentToCancel))
        {
            $description = "The customer switched to a different payment flow.";
            $metadata = null;
            $this->config->getStripeClient()->paymentIntents->update($paymentIntentToCancel->id, [
                "description" => $description,
                "metadata" => $metadata
            ]);
            $paymentIntentToCancel->cancel();
        }

        $this->paymentIntent = null;
    }

    public function isDifferentFrom($paymentIntent, $params, $quote, $order = null)
    {
        $expectedValues = [];

        foreach ($this->paymentIntentHelper->getUpdateableParams($params, $paymentIntent) as $key)
        {
            if (empty($params[$key]))
                $expectedValues[$key] = "unset";
            else
                $expectedValues[$key] = $params[$key];
        }

        return $this->compare->isDifferent($paymentIntent, $expectedValues);
    }

    public function requiresAction($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        return (
            !empty($paymentIntent->status) &&
            $paymentIntent->status == $this::REQUIRES_ACTION
        );
    }

    public function setTransactionDetails(\Magento\Payment\Model\InfoInterface $payment, $intent)
    {
        if ($this->tokenHelper->isPaymentIntentToken($intent->id))
        {
            // Setup Intents are not used for payments, so we don't set the transaction ID
            $payment->setTransactionId($intent->id);
            $payment->setLastTransId($intent->id);
        }

        $payment->setIsTransactionClosed(false);
        $payment->setIsFraudDetected(false);

        if (!empty($intent->latest_charge))
        {
            $payment->setIsTransactionPending(false);
            $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted
        }
        else if ($payment->getOrder()->getGrandTotal() == 0)
        {
            // Case with trial subscriptions and start dates
            $payment->setIsTransactionPending(false);
            $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted
        }
        else
        {
            $payment->setIsTransactionPending(true);
            $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        }

        // Let's save the Stripe customer ID on the order's payment in case the customer registers after placing the order
        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);
    }

    public function processSuccessfulOrder($order, $intent)
    {
        $this->setTransactionDetails($order->getPayment(), $intent);

        $shouldCreateInvoice = $order->canInvoice() && $this->config->isAuthorizeOnly() && $this->config->isAutomaticInvoicingEnabled();

        if ($shouldCreateInvoice)
        {
            $invoice = $order->prepareInvoice();
            $invoice->setTransactionId($intent->id);
            $invoice->register();
            $order->addRelatedObject($invoice);
        }
    }

    public function processRecurringSubscriptionOrder($order, $paymentIntent)
    {
        $this->setTransactionDetails($order->getPayment(), $paymentIntent);
        $order->setCanSendNewEmailFlag($this->config->isSubscriptionRenewalEmailEnabled());
    }

    public function processPendingOrder($order, $intent)
    {
        $payment = $order->getPayment();

        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);

        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted

        if ($this->paymentIntentHelper->requiresOfflineAction($intent))
            $order->setCanSendNewEmailFlag(true);
        else
            $order->setCanSendNewEmailFlag(false);

        if (strpos($intent->id, "seti_") === 0 && in_array($intent->status, ['processing', 'succeeded']))
        {
            $payment->setTransactionId("cannot_capture_subscriptions");
        }
        else if (strpos($intent->id, "pi_") === 0)
        {
            $payment->setTransactionId($intent->id);
        }

        $this->deregisterInvoice($order);
    }

    // For redirect-based payment methods (iDEAL, Klarna, etc.), Magento has already
    // registered an Open invoice via CaptureOperation::invoice() before we discovered
    // the redirect requirement. Strip it back out so the merchant doesn't end up with
    // a Pending invoice on an order that may never be paid. The invoice will be
    // (re)created in PAID state by the charge.succeeded webhook handler.
    private function deregisterInvoice($order)
    {
        $unsavedInvoice = $this->redirectInvoiceHelper->findUnsavedInvoice($order);

        if ($unsavedInvoice)
        {
            $this->redirectInvoiceHelper->deregister($order, $unsavedInvoice);
        }
    }

    public function processPendingOrderWithoutIntent($order)
    {
        $payment = $order->getPayment();
        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        $order->setCanSendNewEmailFlag(false);

        if ($this->customer->getStripeId())
            $payment->setAdditionalInformation("customer_stripe_id", $this->customer->getStripeId());

        $this->deregisterInvoice($order);
    }

    public function processTrialSubscriptionOrder($order, $subscription)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);
        $payment->setAdditionalInformation("is_trial_subscription_setup", true);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted
        $payment->setIsTransactionClosed(true);
        $payment->setIsFraudDetected(false);
        $this->paymentState->setSubscriptionWithoutPayment($subscription);
    }

    public function processFutureSubscriptionOrder($order, $subscription)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);
        $payment->setAdditionalInformation("is_future_subscription_setup", true);
        $payment->setAdditionalInformation("subscription_id", $subscription->id);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(true);
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        $payment->setIsTransactionClosed(false);
        $payment->setIsFraudDetected(false);
        $this->paymentState->setSubscriptionWithoutPayment($subscription);
    }

}
