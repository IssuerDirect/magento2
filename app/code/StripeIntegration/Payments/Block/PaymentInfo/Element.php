<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

class Element extends \StripeIntegration\Payments\Block\PaymentInfo\Checkout
{
    private $paymentIntents = [];
    public $subscription = null;
    private $setupIntents = [];
    private $stripePaymentIntent;
    private $stripePaymentMethodFactory;
    private $helper;
    private $paymentsConfig;
    private $tokenHelper;
    private $orderHelper;
    private $paymentMethod;
    private $currencyHelper;
    private $radarHelper;
    private $confirmationTokenFactory;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \Magento\Framework\App\RequestInterface $request,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory,
        \StripeIntegration\Payments\Model\Stripe\RefundFactory $refundModelFactory,
        \StripeIntegration\Payments\Helper\Radar $radarHelper,
        \StripeIntegration\Payments\Helper\Installments $installmentsHelper,
        \StripeIntegration\Payments\Model\Stripe\ConfirmationTokenFactory $confirmationTokenFactory,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $config,
            $request,
            $areaCodeHelper,
            $helper,
            $paymentMethodHelper,
            $subscriptions,
            $invoicePaymentsHelper,
            $tokenHelper,
            $currencyHelper,
            $paymentsConfig,
            $stripePaymentMethodFactory,
            $refundModelFactory,
            $installmentsHelper,
            $data
        );

        $this->radarHelper = $radarHelper;
        $this->stripePaymentIntent = $stripePaymentIntent;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->tokenHelper = $tokenHelper;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->currencyHelper = $currencyHelper;
        $this->confirmationTokenFactory = $confirmationTokenFactory;
    }

    public function getTemplate()
    {
        $info = $this->getInfo();

        if (!$this->paymentsConfig->getStripeClient())
            return null;

        if (!$this->isAllowedAction())
            return 'StripeIntegration_Payments::paymentInfo/generic.phtml';

        if ($info && $info->getAdditionalInformation("is_subscription_update"))
            return 'StripeIntegration_Payments::paymentInfo/subscription_update.phtml';

        return 'StripeIntegration_Payments::paymentInfo/element.phtml';
    }

    private function getPaymentMethodToken()
    {
        $info = $this->getInfo();
        if ($info && $info->getAdditionalInformation("token"))
        {
            $token = $info->getAdditionalInformation("token");
            $token = $this->tokenHelper->cleanToken($token);
            if ($this->tokenHelper->isPaymentMethodToken($token))
                return $token;
        }

        return null;
    }

    public function getPaymentMethod()
    {
        if (isset($this->paymentMethod))
            return $this->paymentMethod;

        $paymentIntent = $this->getPaymentIntent();
        $setupIntent = $this->getSetupIntent();
        $paymentMethodToken = $this->getPaymentMethodToken();

        $paymentMethod = $paymentIntent->payment_method ??
            $setupIntent->payment_method ??
            $paymentMethodToken;

        if (isset($paymentMethod->id))
        {
            return $this->paymentMethod = $paymentMethod;
        }
        else if ($this->tokenHelper->isPaymentMethodToken($paymentMethod))
        {
            try
            {
                $paymentMethod = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($paymentMethod)->getStripeObject();
                return $this->paymentMethod = $paymentMethod;
            }
            // @codeCoverageIgnoreStart
            catch (\Exception $e)
            {
                $this->helper->logInfo("Could not retrieve payment method from Stripe: " . $e->getMessage());
            }
            // @codeCoverageIgnoreEnd
        }

        // This code hits at the Review page of the multishipping checkout, displays the PM icon and label under the payment method info section.
        $confirmationTokenId = $this->getInfo()->getAdditionalInformation("confirmation_token");
        if ($this->tokenHelper->isConfirmationToken($confirmationTokenId))
        {
            try
            {
                $confirmationToken = $this->confirmationTokenFactory->create()->fromId($confirmationTokenId);
                if ($confirmationToken->getPaymentMethodPreview())
                    return $this->paymentMethod = $confirmationToken->getPaymentMethodPreview();
            }
            // @codeCoverageIgnoreStart
            catch (\Exception $e)
            {
                $this->helper->logInfo("Could not retrieve confirmation token from Stripe: " . $e->getMessage());
            }
            // @codeCoverageIgnoreEnd
        }

        return $this->paymentMethod = null;
    }

    public function isMultiShipping()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->metadata["Multishipping"]))
            return false;

        return true;
    }

    public function getFormattedAmount()
    {
        /** @var ?\Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->amount))
            return '';

        return $this->currencyHelper->formatStripePrice($paymentIntent->amount, $paymentIntent->currency);
    }

    public function getFormattedMultishippingAmount()
    {
        $total = $this->getFormattedAmount();

        $paymentIntent = $this->getPaymentIntent();

        /** @var \Magento\Payment\Model\InfoInterface $info */
        $info = $this->getInfo();
        if (!is_numeric($info->getAmountOrdered()))
            return $total;

        $partial = $this->currencyHelper->addCurrencySymbol($info->getAmountOrdered(), $paymentIntent->currency);

        return $partial;
    }

    public function getPaymentStatus()
    {
        $paymentIntent = $this->getPaymentIntent();

        return $this->getPaymentIntentStatus($paymentIntent);
    }

    public function getSubscription()
    {
        if (empty($this->subscription))
        {
            $info = $this->getInfo();
            if ($info && $info->getAdditionalInformation("subscription_id"))
            {
                try
                {
                    $subscriptionId = $info->getAdditionalInformation("subscription_id");
                    $this->subscription = $this->paymentsConfig->getStripeClient()->subscriptions->retrieve($subscriptionId);
                }
                // @codeCoverageIgnoreStart
                catch (\Exception $e)
                {
                    $this->helper->logInfo("Could not retrieve subscription from Stripe: " . $e->getMessage());
                    return null;
                }
                // @codeCoverageIgnoreEnd
            }
        }

        return $this->subscription;
    }

    public function getCustomerId()
    {
        $info = $this->getInfo();
        if ($info && $info->getAdditionalInformation("customer_stripe_id"))
            return $info->getAdditionalInformation("customer_stripe_id");

        return null;
    }

    public function getPaymentIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->tokenHelper->cleanToken($transactionId);

        if (empty($transactionId) || strpos($transactionId, "pi_") !== 0)
            return null;

        if (array_key_exists($transactionId, $this->paymentIntents))
            return $this->paymentIntents[$transactionId];

        try
        {
            $paymentIntent = $this->stripePaymentIntent->fromPaymentIntentId($transactionId, ['payment_method', 'latest_charge', 'latest_charge.refunds', 'charges'])->getStripeObject();
            return $this->paymentIntents[$transactionId] = $paymentIntent;
        }
        // @codeCoverageIgnoreStart
        catch (\Exception $e)
        {
            return $this->paymentIntents[$transactionId] = null;
        }
        // @codeCoverageIgnoreEnd
    }

    public function getSetupIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->tokenHelper->cleanToken($transactionId);
        $clientSecret = $this->getInfo()->getAdditionalInformation("client_secret");
        $setupIntentId = null;

        if ($this->tokenHelper->isSetupIntentToken($transactionId))
            $setupIntentId = $transactionId;
        else if ($this->tokenHelper->isSetupIntentToken($clientSecret))
            $setupIntentId = $this->tokenHelper->getSetupIntentIdFromClientSecret($clientSecret);

        if (empty($setupIntentId))
            return null;

        if (array_key_exists($setupIntentId, $this->setupIntents))
            return $this->setupIntents[$setupIntentId];

        try
        {
            return $this->setupIntents[$setupIntentId] = $this->paymentsConfig->getStripeClient()->setupIntents->retrieve($setupIntentId, ['expand' => ['payment_method']]);
        }
        // @codeCoverageIgnoreStart
        catch (\Exception $e)
        {
            return $this->setupIntents[$setupIntentId] = null;
        }
        // @codeCoverageIgnoreEnd
    }

    public function getMode()
    {
        $paymentIntent = $this->getPaymentIntent();
        $setupIntent = $this->getSetupIntent();

        if ($paymentIntent && $paymentIntent->livemode)
            return "";
        else if ($setupIntent && $setupIntent->livemode)
            return "";

        return "test/";
    }

    // For subscription updates
    public function getSubscriptionOrderUrl($orderIncrementId)
    {
        if (empty($orderIncrementId))
            return null;

        $order = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$order || !$order->getId())
            return null;

        return $this->helper->getUrl('sales/order/view', ['order_id' => $order->getId()]);
    }

    public function getOriginalSubscriptionOrderIncrementId()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        $incrementId = $info->getAdditionalInformation("original_order_increment_id");
        if (empty($incrementId))
            return null;

        return $incrementId;
    }

    public function getNewSubscriptionOrderIncrementId()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        $incrementId = $info->getAdditionalInformation("new_order_increment_id");
        if (empty($incrementId))
            return null;

        return $incrementId;
    }

    public function getPreviousSubscriptionAmount()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        return $info->getAdditionalInformation("previous_subscription_amount");
    }

    public function getFormattedSubscriptionAmount()
    {
        if ($this->getPreviousSubscriptionAmount())
            return null;

        return parent::getFormattedSubscriptionAmount();
    }

    public function getFormattedNewSubscriptionAmount()
    {
        if (!$this->getPreviousSubscriptionAmount())
            return null;

        $info = $this->getInfo();
        if ($info && $info->getAdditionalInformation("new_subscription_amount"))
            return $info->getAdditionalInformation("new_subscription_amount");

        return parent::getFormattedSubscriptionAmount();
    }

    /**
     * prepare the risk element class
     *
     * @param int $riskScore
     * @param string $riskLevel
     * @return string
     */
    public function getRiskElementClass($riskScore = 0, $riskLevel = \StripeIntegration\Payments\Helper\Radar::RISK_LEVEL_NA)
    {
        return $this->radarHelper->getRiskElementClass($riskScore, $riskLevel);
    }
}
