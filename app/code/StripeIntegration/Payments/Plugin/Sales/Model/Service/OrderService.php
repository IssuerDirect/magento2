<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Service;

class OrderService
{
    private $helper;
    private $helperFactory;
    private $quoteHelper;
    private $paymentMethodHelper;
    private $loggerHelper;
    private $orderHelper;
    private $paymentState;
    private $checkoutCrashHelper;
    private $radarHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Helper\CheckoutCrash $checkoutCrashHelper,
        \StripeIntegration\Payments\Helper\Radar $radarHelper,
        \StripeIntegration\Payments\Model\Order\PaymentState $paymentState
    ) {
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->helperFactory = $helperFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->loggerHelper = $loggerHelper;
        $this->checkoutCrashHelper = $checkoutCrashHelper;
        $this->paymentState = $paymentState;
        $this->radarHelper = $radarHelper;
    }

    public function aroundPlace($subject, \Closure $proceed, $order)
    {
        try
        {
            if (!empty($order) && !empty($order->getQuoteId()))
            {
                $this->quoteHelper->quoteId = $order->getQuoteId();
            }

            $savedOrder = $proceed($order);

            return $this->postProcess($savedOrder);
        }
        catch (\Exception $e)
        {
            $helper = $this->getHelper();
            $msg = $e->getMessage();

            if ($this->loggerHelper->isAuthenticationRequiredMessage($msg))
            {
                throw $e;
            }
            else
            {
                if (($this->paymentState->isPaid() || $this->paymentState->isSubscriptionWithoutPayment()) && empty($savedOrder))
                {
                    $this->checkoutCrashHelper->log($this->paymentState, $e)
                        ->notifyAdmin($this->paymentState, $e)
                        ->deactivateCart();

                    $helper->logError($e->getMessage(), $e->getTraceAsString());

                    throw $e;
                }

                // Payment failed errors
                return $helper->throwError($e->getMessage(), $e);
            }
        }
    }

    public function postProcess($order)
    {
        if (strstr($order->getPayment()->getMethod(), "stripe_") !== false)
        {
            try
            {
                $this->paymentMethodHelper->saveOrderPaymentMethodById($order, $order->getPayment()->getAdditionalInformation("token"));
            }
            catch (\Exception $e)
            {
                $this->loggerHelper->logError("Failed to save order payment method: " . $e->getMessage(), $e->getTraceAsString());
            }

            try
            {
                $this->radarHelper->setOrderRiskData($order);
            }
            catch (\Exception $e)
            {
                $this->loggerHelper->logError("Failed to save order risk data: " . $e->getMessage(), $e->getTraceAsString());
            }

            $this->orderHelper->saveOrderFields($order, [
                'stripe_payment_method_type',
                'stripe_radar_risk_score',
                'stripe_radar_risk_level'
            ]);
        }

        $helper = $this->getHelper();
        switch ($order->getPayment()->getMethod())
        {
            case "stripe_payments_invoice":
                $comment = __("A payment is pending for this order.");
                $helper->setOrderState($order, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment);
                $this->orderHelper->saveOrderFields($order, ['state', 'status']);
                break;
            default:
                // Deferred webhooks (charge.succeeded etc.) are processed after QuoteManagement
                // releases the placement lock — see QuoteManagement::aroundSubmit().
                break;
        }

        return $order;
    }

    protected function getHelper()
    {
        if (!isset($this->helper))
        {
            $this->helper = $this->helperFactory->create();
        }

        return $this->helper;
    }
}
