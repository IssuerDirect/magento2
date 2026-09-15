<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Exception\OrderPlacedAndPaidException;

class OrderValidator
{
    private $orderHelper;
    private $stripePaymentIntentFactory;
    private $convert;

    public function __construct(
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentFactory,
        \StripeIntegration\Payments\Helper\Convert $convert
    )
    {
        $this->orderHelper = $orderHelper;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
        $this->convert = $convert;
    }

    public function validate($order)
    {
        if (empty($order))
            throw new GenericException("No order specified.");

        // Check if the order is already placed
        $existingOrder = $this->findExistingOrder($order->getIncrementId(), $order->getQuoteId());
        if ($existingOrder && !$existingOrder->getIsMultiShipping())
        {
            // Check that the existing order is not paid
            $this->validateOrderNotPaid($existingOrder, $order);
        }
    }

    private function validateOrderNotPaid($existingOrder, $newOrder)
    {
        $paymentIntentId = $this->orderHelper->getPaymentIntentId($existingOrder);
        if (!$paymentIntentId)
        {
            // Trial and future start date subscription orders are set up without a payment,
            // so they have no payment intent. They should still be protected against duplicate placement.
            if ($this->orderHelper->isSubscriptionWithoutPayment($existingOrder))
            {
                throw new OrderPlacedAndPaidException(true);
            }
            return;
        }

        $stripePaymentIntentModel = $this->stripePaymentIntentFactory->create()->fromPaymentIntentId($paymentIntentId);
        if ($stripePaymentIntentModel->isFinalized())
        {
            $amount = $newOrder->getGrandTotal();
            $currency = $newOrder->getOrderCurrencyCode();
            $stripeAmount = $stripePaymentIntentModel->getAmount();
            $stripeCurrency = $stripePaymentIntentModel->getCurrency();
            $amountsMatch = ($stripeAmount == $this->convert->magentoAmountToStripeAmount($amount, $currency));
            $amountsMatch = ($amountsMatch && ($stripeCurrency == strtolower($currency)));
            throw new OrderPlacedAndPaidException($amountsMatch);
        }
    }

    private function findExistingOrder($orderIncrementId, $quoteId)
    {
        $order = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);
        if ($order)
        {
            return $order;
        }

        $ordersCollection = $this->orderHelper->loadOrdersByQuoteId($quoteId);
        if ($ordersCollection && $ordersCollection->getSize() >= 1)
        {
            return $ordersCollection->getFirstItem();
        }

        return null;
    }
}