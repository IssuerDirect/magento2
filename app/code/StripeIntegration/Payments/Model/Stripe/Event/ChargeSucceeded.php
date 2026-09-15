<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Helper\Radar;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class ChargeSucceeded
{
    use StripeObjectTrait;

    private $paymentIntentFactory;
    private $paymentMethodHelper;
    private $webhooksHelper;
    private $config;
    private $helper;
    private $orderHelper;
    private $quoteHelper;
    private $multishippingHelper;
    private $json;
    private $currencyHelper;
    private $convert;
    private $addressHelper;
    private $stripeChargeModelFactory;
    private $invoicePaymentsHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Model\Stripe\ChargeFactory $stripeChargeModelFactory,
        \Magento\Framework\Serialize\Serializer\Json $json
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->config = $config;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->quoteHelper = $quoteHelper;
        $this->multishippingHelper = $multishippingHelper;
        $this->json = $json;
        $this->currencyHelper = $currencyHelper;
        $this->convert = $convert;
        $this->addressHelper = $addressHelper;
        $this->stripeChargeModelFactory = $stripeChargeModelFactory;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
    }

    public function process($arrEvent, $object)
    {
        if (!empty($object['metadata']['Multishipping']))
        {
            $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);
            $paymentIntentModel = $this->paymentIntentFactory->create();

            foreach ($orders as $order)
            {
                $successfulOrders = $this->multishippingHelper->getSuccessfulOrdersForQuoteId($order->getQuoteId());
                $this->onMultishippingChargeSucceeded($successfulOrders, $order->getQuoteId());
                break;
            }

            return;
        }

        if ($this->webhooksHelper->wasCapturedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.dispute.created']);

        // Set the risk score and level
        $stripeChargeModel = $this->stripeChargeModelFactory->create()->fromChargeId($object['id']);
        $order->setStripeRadarRiskScore($stripeChargeModel->getRiskScore());
        $order->setStripeRadarRiskLevel($stripeChargeModel->getRiskLevel());

        // Set the Stripe payment method
        $this->paymentMethodHelper->saveOrderPaymentMethodById($order, $object['payment_method']);

        $stripeInvoice = null;
        if (!empty($object['payment_intent']))
        {
            $stripeInvoice = $this->invoicePaymentsHelper->getInvoiceFromPaymentIntentId($object['payment_intent']);
        }

        if ($stripeInvoice)
        {
            if ($stripeInvoice->billing_reason == "subscription_cycle" // A subscription has renewed
                || $stripeInvoice->billing_reason == "subscription_update" // A trial subscription was manually ended
                || $stripeInvoice->billing_reason == "subscription_threshold" // A billing threshold was reached
            )
            {
                // We may receive a charge.succeeded event from a recurring subscription payment. In that case we want to create
                // a new order for the new payment, rather than registering the charge against the original order.
                return;
            }
        }

        if (empty($object['payment_intent']))
            throw new WebhookException("This charge was not created by a payment intent.");

        $this->quoteHelper->deactivateQuoteById($order->getQuoteId());
        $this->updateOrderAddresses($order, $object);

        $wasTransactionPending = $order->getPayment()->getAdditionalInformation("is_transaction_pending");

        $transactionId = $object['payment_intent'];

        $payment = $order->getPayment();

        // If there is an installment plan, and it is not set on the payment, set it here.
        // This applies to the case where Stripe Checkout redirect is used.
        if ($stripeChargeModel->isCardPaymentMethod() &&
            $stripeChargeModel->getInstallmentsPlan() &&
            !$payment->getAdditionalInformation('selected_installment_plan')
        ) {
            $payment->setAdditionalInformation(
                'selected_installment_plan',
                $this->json->serialize($stripeChargeModel->getInstallmentsPlan())
            );
        }

        $payment->setTransactionId($transactionId)
            ->setLastTransId($transactionId)
            ->setIsTransactionPending(false)
            ->setAdditionalInformation("is_transaction_pending", false) // this is persisted
            ->setIsTransactionClosed(0)
            ->setIsFraudDetected(false);

        if (!$order->getEmailSent() && $wasTransactionPending)
        {
            $this->orderHelper->sendNewOrderEmailFor($order);
        }

        $this->onTransaction($order, $object, $transactionId);
        $this->updatePaymentIntentMetadata($object['payment_intent'], $order);
        $this->invoiceOrder($order, $object);

        if ($this->config->isStripeRadarEnabled() && !empty($object['outcome']['type']) && $object['outcome']['type'] == "manual_review") {
            $order->setHoldBeforeState($order->getState());
            $order->setHoldBeforeStatus($order->getStatus());
            if (!empty($object['outcome']['seller_message'])) {
                $comment = __('Payment set in manual review in Stripe with the following message: %1', $object['outcome']['seller_message']);
            } else {
                $comment = __('Payment set in manual review in Stripe.');
            }

            $order->setState(Radar::MANUAL_REVIEW_STATE_CODE)
                ->setStatus(Radar::MANUAL_REVIEW_STATUS_CODE)
                ->addStatusToHistory(Radar::MANUAL_REVIEW_STATUS_CODE, $comment, false);
        }

        $this->addAdaptivePricingComment($order, $object);

        $order = $this->orderHelper->saveOrder($order);

        // Update the payment intents table, because the payment method was created after the order was placed
        $paymentIntentModel = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
        $quoteId = $paymentIntentModel->getQuoteId();
        if ($quoteId == $order->getQuoteId())
        {
            $paymentIntentModel->setPmId($object['payment_method']);
            $paymentIntentModel->setOrderId($order->getId());
            if (is_numeric($order->getCustomerId()) && $order->getCustomerId() > 0)
                $paymentIntentModel->setCustomerId($order->getCustomerId());
            $paymentIntentModel->save();
        }

    }

    public function updatePaymentIntentMetadata($paymentIntentId, $order)
    {
        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);
        if (empty($paymentIntent->metadata->{"Order #"}))
        {
            $this->config->getStripeClient()->paymentIntents->update($paymentIntentId, [
                'metadata' => $this->config->getMetadata($order),
                'description' => $this->orderHelper->getOrderDescription($order)
            ]);
        }
    }

    public function invoiceOrder($order, $object)
    {
        $amountCaptured = ($object["captured"] ? $object['amount_captured'] : 0);
        if ($amountCaptured <= 0)
            return;

        $orderTotal = $this->convert->magentoAmountToStripeAmount($order->getGrandTotal(), $order->getOrderCurrencyCode());

        if ($amountCaptured >= $orderTotal)
        {
            // Full payment received, or accidental overpayment in case of bank transfers
            $this->helper->invoiceOrder($order, $object['payment_intent'], \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, true);
        }
        else if ($amountCaptured < $orderTotal)
        {
            // Partial payment received
            $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToOrderAmount($amountCaptured, $object['currency'], $order), $object['currency']);
            $this->orderHelper->addOrderComment(__("A partial payment of %1 was received. Please invoice the order manually.", $humanReadableAmount), $order);
        }
    }

    public function onMultishippingChargeSucceeded($successfulOrders, $quoteId)
    {
        $this->multishippingHelper->onPaymentConfirmed($quoteId, $successfulOrders);

        foreach ($successfulOrders as $order)
        {
            $this->orderHelper->sendNewOrderEmailFor($order);
        }
    }

    public function onTransaction($order, $object, $transactionId)
    {
        $action = __("Collected");
        if ($object["captured"] == false)
        {
            if ($order->getState() != "pending" && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Authorized");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
            $transactionAmount = $this->convert->stripeAmountToOrderAmount($object['amount'], $object['currency'], $order);
        }
        else
        {
            if ($order->getTotalPaid() >= $order->getGrandTotal() && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Captured");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
            $transactionAmount = $this->convert->stripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
        }

        $transaction = $order->getPayment()->addTransaction($transactionType, null, false);
        $transaction->setAdditionalInformation("amount", (string)$transactionAmount);
        $transaction->setAdditionalInformation("currency", $object['currency']);
        $transaction->save();

        if ($order->getState() == "canceled")
        {
            $this->orderHelper->addOrderComment(__("The order was unexpectedly in a canceled state when a payment was collected. Attempting to re-open the order."), $order);
            $this->resetItemQuantities($order);
        }

        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($transactionAmount, $object['currency']);
        $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", $action, $humanReadableAmount, $transactionId);
        $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = false);
    }

    public function resetItemQuantities($order)
    {
        foreach ($order->getAllItems() as $item)
        {
            // Check if the item is cancelable
            if ($item->getQtyCanceled() > 0) {
                $item->setQtyCanceled(0);
            }

            // Set quantity to invoice
            $item->setQtyToInvoice($item->getQtyOrdered() - $item->getQtyInvoiced());
        }
    }

    // Wallets hide personal data for data privacy reasons. We only get these data after the payment is completed.
    private function updateOrderAddresses($order, $object)
    {
        if (!empty($object['billing_details']))
        {
            $telephoneHasPlaceholder = $order->getBillingAddress()->getTelephone() === '0000000000';
            $firstname = $this->addressHelper->getFirstnameFromStripeAddress($object['billing_details']);
            $lastname = $this->addressHelper->getLastnameFromStripeAddress($object['billing_details']);
            $phone = $this->addressHelper->getPhoneFromStripeAddress($object['billing_details']);
            $email = $this->addressHelper->getEmailFromStripeAddress($object['billing_details']);
            $firstname && empty($order->getBillingAddress()->getFirstname()) ? $order->getBillingAddress()->setFirstname($firstname) : null;
            $lastname && empty($order->getBillingAddress()->getLastname()) ? $order->getBillingAddress()->setLastname($lastname) : null;
            $phone && (empty($order->getBillingAddress()->getTelephone()) || $telephoneHasPlaceholder) ? $order->getBillingAddress()->setTelephone($phone) : null;
            $email && empty($order->getBillingAddress()->getEmail()) ? $order->getBillingAddress()->setEmail($email) : null;
        }

        if (!empty($object['shipping']) && !$order->getIsVirtual())
        {
            $telephoneHasPlaceholder = $order->getShippingAddress()->getTelephone() === '0000000000';
            $firstname = $this->addressHelper->getFirstnameFromStripeAddress($object['shipping']);
            $lastname = $this->addressHelper->getLastnameFromStripeAddress($object['shipping']);
            $phone = $this->addressHelper->getPhoneFromStripeAddress($object['shipping']);
            $email = $this->addressHelper->getEmailFromStripeAddress($object['shipping']);
            $firstname && empty($order->getShippingAddress()->getFirstname()) ? $order->getShippingAddress()->setFirstname($firstname) : null;
            $lastname && empty($order->getShippingAddress()->getLastname()) ? $order->getShippingAddress()->setLastname($lastname) : null;
            $phone && (empty($order->getShippingAddress()->getTelephone()) || $telephoneHasPlaceholder) ? $order->getShippingAddress()->setTelephone($phone) : null;
            $email && empty($order->getShippingAddress()->getEmail()) ? $order->getShippingAddress()->setEmail($email) : null;
        }
    }

    private function addAdaptivePricingComment($order, $object)
    {
        if (empty($object['presentment_details'])) {
            return;
        }

        $presentmentCurrency = strtolower((string)($object['presentment_details']['presentment_currency'] ?? ''));
        $chargeCurrency = strtolower((string)($object['currency'] ?? ''));

        if (empty($presentmentCurrency) || $presentmentCurrency === $chargeCurrency) {
            return;
        }

        $formattedAmount = $this->currencyHelper->formatStripePriceWithFallback(
            (int)$object['presentment_details']['presentment_amount'],
            (string)$object['presentment_details']['presentment_currency']
        );

        $order->addStatusHistoryComment(__('Customer was charged %1 via Stripe Adaptive Pricing.', $formattedAmount));
    }
}