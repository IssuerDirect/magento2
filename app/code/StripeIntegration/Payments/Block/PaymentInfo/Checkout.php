<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

class Checkout extends \Magento\Payment\Block\ConfigurableInfo
{
    public $checkoutSession = null;
    private $helper;
    private $paymentsConfig;

    private $subscriptions;
    private $paymentMethodHelper;
    private $invoicePaymentsHelper;
    private $stripePaymentMethodFactory;
    private $setupIntent;
    private $paymentIntent;
    private $paymentMethod;
    private $tokenHelper;
    private $request;
    private $areaCodeHelper;
    private $currencyHelper;
    private $installmentsHelper;
    private $refundModelFactory;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \Magento\Framework\App\RequestInterface $request,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory,
        \StripeIntegration\Payments\Model\Stripe\RefundFactory $refundModelFactory,
        \StripeIntegration\Payments\Helper\Installments $installmentsHelper,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->request = $request;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->helper = $helper;
        $this->subscriptions = $subscriptions;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->tokenHelper = $tokenHelper;
        $this->currencyHelper = $currencyHelper;
        $this->installmentsHelper = $installmentsHelper;
        $this->refundModelFactory = $refundModelFactory;
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

        return 'StripeIntegration_Payments::paymentInfo/checkout.phtml';
    }

    public function isAllowedAction()
    {
        if (!$this->areaCodeHelper->isAdmin())
            return true;

        $allowedAdminActions = ["view", "new", "email"];
        $action = $this->request->getActionName();
        if (in_array($action, $allowedAdminActions))
            return true;

        return false;
    }

    public function getFormattedAmount()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (empty($checkoutSession->amount_total))
            return '';

        return $this->currencyHelper->formatStripePrice($checkoutSession->amount_total, $checkoutSession->currency);
    }

    /**
     * Returns the presentment_details object when the customer was charged in a different currency
     * than the settlement currency (i.e. Adaptive Pricing was applied). Checks the latest charge
     * first; falls back to the Checkout Session. Returns null if currencies match or data is unavailable.
     */
    public function getPresentmentDetails()
    {
        $charge = $this->getCharge();
        /** @var \Stripe\Charge|\Stripe\StripeObject $source */
        $source = !empty($charge->presentment_details) ? $charge : $this->getCheckoutSession();

        if (empty($source->presentment_details)) {
            return null;
        }

        $presentmentCurrency = strtolower((string)($source->presentment_details->presentment_currency ?? ''));
        $sourceCurrency = strtolower((string)($source->currency ?? ''));

        if (empty($presentmentCurrency) || $presentmentCurrency === $sourceCurrency) {
            return null;
        }

        return $source->presentment_details;
    }

    public function getFormattedPresentmentAmount()
    {
        $details = $this->getPresentmentDetails();

        if (empty($details)) {
            return null;
        }

        return $this->currencyHelper->formatStripePriceWithFallback(
            $details->presentment_amount,
            $details->presentment_currency
        );
    }

    public function getFormattedSubscriptionAmount()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription->plan))
            return '';

        return $this->subscriptions->formatInterval(
            $subscription->plan->amount,
            $subscription->plan->currency,
            $subscription->plan->interval_count,
            $subscription->plan->interval
        );
    }

    public function getPaymentMethod()
    {
        if (!empty($this->paymentMethod))
            return $this->paymentMethod;

        $checkoutSession = $this->getCheckoutSession();
        $paymentIntent = $this->getPaymentIntent();
        $setupIntent = $this->getSetupIntent();

        $paymentMethod = $paymentIntent->payment_method ??
            $setupIntent->payment_method ??
            $checkoutSession->subscription->default_payment_method ??
            null;

        if (isset($paymentMethod->id))
        {
            return $this->paymentMethod = $paymentMethod;
        }
        else if ($this->tokenHelper->isPaymentMethodToken($paymentMethod))
        {
            $paymentMethod = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($paymentMethod);
            return $this->paymentMethod = $paymentMethod->getStripeObject();
        }

        return null;
    }

    public function getPaymentMethodCode()
    {
        $info = $this->getInfo();
        if ($info)
        {
            $token = $info->getAdditionalInformation('token');
            if ($token && $this->tokenHelper->isExternalPaymentMethodToken($token))
                return substr($token, 9); // Remove "external_" prefix
        }

        $method = $this->getPaymentMethod();

        if (!empty($method->type))
            return $method->type;

        return null;
    }

    public function isExternalPaymentMethod()
    {
        $info = $this->getInfo();
        if (!$info)
            return false;

        $token = $info->getAdditionalInformation('token');
        return $token && $this->tokenHelper->isExternalPaymentMethodToken($token);
    }

    public function getPaymentMethodName($hideLast4 = false)
    {
        $paymentMethodCode = $this->getPaymentMethodCode();

        switch ($paymentMethodCode)
        {
            case "card":
                $method = $this->getPaymentMethod();
                return $this->paymentMethodHelper->getCardLabel($method->card, $hideLast4);
            default:
                return $this->paymentMethodHelper->getPaymentMethodName($paymentMethodCode);
        }
    }

    public function getPaymentMethodIconUrl($format = null)
    {
        $method = $this->getPaymentMethod();

        if (!$method)
            return null;

        return $this->paymentMethodHelper->getIcon($method, $format);
    }

    public function getWalletIconUrl()
    {
        $method = $this->getPaymentMethod();

        if (!$method)
            return null;

        $type = $method->type;
        if ($type == 'link' || !isset($method->$type->wallet->type))
            return null;

        return $this->paymentMethodHelper->getPaymentMethodIcon($method->$type->wallet->type);
    }

    public function getCheckoutSession(): ?\Stripe\Checkout\Session
    {
        if ($this->checkoutSession)
            return $this->checkoutSession;

        try
        {
            $sessionId = $this->getInfo()->getAdditionalInformation("checkout_session_id");
            $checkoutSession = $this->paymentsConfig->getStripeClient()->checkout->sessions->retrieve($sessionId, [
                'expand' => [
                    'payment_intent',
                    'payment_intent.payment_method',
                    'setup_intent',
                    'setup_intent.payment_method',
                    'subscription',
                    'subscription.default_payment_method',
                    'subscription.latest_invoice.payment_intent'
                ]
            ]);

            return $this->checkoutSession = $checkoutSession;
        }
        catch (\Exception $e)
        {
            $this->helper->logInfo("Could not retrieve checkout session: " . $e->getMessage());
            return null;
        }
    }

    public function getPaymentIntent()
    {
        if (!empty($this->paymentIntent))
            return $this->paymentIntent;

        $transactionId = $this->getTransactionId();
        if ($transactionId && strpos($transactionId, "pi_") === 0)
        {
            return $this->paymentIntent = $this->hydratePaymentIntent($transactionId);
        }

        $checkoutSession = $this->getCheckoutSession();

        if (!empty($checkoutSession->payment_intent))
        {
            return $this->paymentIntent = $this->hydratePaymentIntent($checkoutSession->payment_intent);
        }

        if (!empty($checkoutSession->subscription->latest_invoice))
        {
            $invoiceId = is_string($checkoutSession->subscription->latest_invoice) ? $checkoutSession->subscription->latest_invoice : $checkoutSession->subscription->latest_invoice->id;
            $paymentIntentId = $this->invoicePaymentsHelper->getLatestPaymentIntentIdFromInvoiceId($invoiceId);
            if ($paymentIntentId)
            {
                return $this->paymentIntent = $this->hydratePaymentIntent($paymentIntentId);
            }
        }

        return null;
    }

    public function getSetupIntent()
    {
        if (!empty($this->setupIntent))
            return $this->setupIntent;

        $checkoutSession = $this->getCheckoutSession();

        if (!empty($checkoutSession->setup_intent))
        {
            return $this->setupIntent = $checkoutSession->setup_intent;
        }

        return null;
    }

    protected function hydratePaymentIntent($paymentIntent)
    {
        if (is_string($paymentIntent))
        {
            try
            {
                return $this->paymentsConfig->getStripeClient()->paymentIntents->retrieve($paymentIntent, ['expand' => ['payment_method', 'latest_charge', 'latest_charge.refunds']]);
            }
            catch (\Exception $e)
            {
                $this->helper->logInfo("Could not retrieve payment intent: " . $e->getMessage());
                return null;
            }
        }

        return $paymentIntent;
    }
    public function getPaymentStatus()
    {
        $checkoutSession = $this->getCheckoutSession();
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent))
        {
            return $this->getPaymentIntentStatus($paymentIntent);
        }
        else if (!empty($checkoutSession->status))
        {
            if ($checkoutSession->status == "open")
                return "pending";
            else if ($checkoutSession->status == "complete")
                return "succeeded";
            else // expired
                return "canceled";
        }
        else
        {
            return "unknown";
        }
    }

    public function getPaymentStatusName()
    {
        $status = $this->getPaymentStatus();
        return ucfirst(str_replace("_", " ", $status));
    }

    public function getSubscriptionStatus()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription))
            return null;

        return $subscription->status;
    }

    public function getSubscriptionStatusName()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription))
            return null;

        if ($subscription->status == "trialing")
            return __("Trial ends %1", date("j M", $subscription->trial_end));

        return ucfirst($subscription->status);
    }

    public function getPaymentIntentStatus(?\Stripe\PaymentIntent $paymentIntent)
    {
        if (empty($paymentIntent->status))
            return null;

        switch ($paymentIntent->status)
        {
            case "requires_payment_method":
            case "requires_confirmation":
            case "requires_action":
                return "pending";
            case "requires_capture":
                return "uncaptured";
            case "canceled":
                if (empty($paymentIntent->latest_charge))
                    return 'canceled';
                /** @var \Stripe\Charge $charge */
                $charge = $paymentIntent->latest_charge;
                if (!empty($charge->failure_code))
                    return "failed";
                else
                    return "canceled";
            case "processing":
            case "succeeded":
                if (!empty($paymentIntent->latest_charge->refunded))
                {
                    if ($this->hasFailedLatestRefund($paymentIntent->latest_charge))
                        return "failed_refund";

                    if ($this->hasPendingRefunds($paymentIntent->latest_charge))
                        return "pending_refund";

                    return "refunded";
                }
                else if (!empty($paymentIntent->latest_charge->amount_refunded))
                {
                    if ($this->hasFailedLatestRefund($paymentIntent->latest_charge))
                        return "failed_refund";

                    if ($this->hasPendingRefunds($paymentIntent->latest_charge))
                        return "pending_refund";

                    return "partial_refund";
                }

                if ($paymentIntent->status == "processing")
                    return "pending";

                return "succeeded";
            default:
                return $paymentIntent->status;
        }
    }

    private function getChargeRefunds($charge)
    {
        if (!empty($charge->refunds->data))
            $refundsData = $charge->refunds->data;
        else if (empty($charge->id))
            return [];
        else
        {
            try
            {
                $refunds = $this->paymentsConfig->getStripeClient()->refunds->all(['charge' => $charge->id, 'limit' => 10]);
                $refundsData = $refunds->data;
            }
            catch (\Exception $e)
            {
                return [];
            }
        }

        $refundModels = [];
        foreach ($refundsData as $refund)
        {
            $refundModels[] = $this->refundModelFactory->create()->fromObject($refund);
        }

        return $refundModels;
    }

    private function hasPendingRefunds($charge)
    {
        foreach ($this->getChargeRefunds($charge) as $refundModel)
        {
            if ($refundModel->isPending())
                return true;
        }

        return false;
    }

    private function hasFailedLatestRefund($charge)
    {
        $refunds = $this->getChargeRefunds($charge);

        if (empty($refunds[0]))
            return false;

        return $refunds[0]->isFailed();
    }

    public function getSubscription()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (!empty($checkoutSession->subscription))
            return $checkoutSession->subscription;

        return null;
    }

    public function getRiskLevelCode()
    {
        $charge = $this->getCharge();

        if (isset($charge->outcome->risk_level))
            return $charge->outcome->risk_level;

        return '';
    }

    public function getRiskScore()
    {
        $charge = $this->getCharge();

        if (isset($charge->outcome->risk_score))
            return $charge->outcome->risk_score;

        return null;
    }

    public function getRiskEvaluation()
    {
        $risk = $this->getRiskLevelCode();
        return ucfirst(str_replace("_", " ", $risk));
    }

    public function getCharge()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->latest_charge))
            return $paymentIntent->latest_charge;

        return null;
    }

    public function getCustomerId()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (isset($checkoutSession->customer) && !empty($checkoutSession->customer))
            return $checkoutSession->customer;

        return null;
    }

    public function getPaymentId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->id))
            return $paymentIntent->id;

        return null;
    }

    public function getTransactionId()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        return $this->tokenHelper->cleanToken($transactionId);
    }

    public function getMode()
    {
        $checkoutSession = $this->getCheckoutSession();

        if ($checkoutSession && $checkoutSession->livemode)
            return "";

        return "test/";
    }

    public function getTitle()
    {
        $info = $this->getInfo();

        // Payment info block in admin area
        if ($info->getAdditionalInformation('payment_location'))
            return __($info->getAdditionalInformation('payment_location'));

        return $this->getMethod()->getTitle();
    }

    public function getVoucherLink()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->next_action->type))
        {
            $type = $paymentIntent->next_action->type;

            if (!empty($paymentIntent->next_action->$type->hosted_voucher_url))
                return $paymentIntent->next_action->$type->hosted_voucher_url;
        }

        return null;
    }

    public function getPaymentMethodVerificationUrl()
    {
        /** @var ?\Stripe\SetupIntent $setupIntent */
        $setupIntent = $this->getSetupIntent();

        if (!empty($setupIntent->next_action->type) && $setupIntent->next_action->type == "verify_with_microdeposits")
            return $setupIntent->next_action->verify_with_microdeposits->hosted_verification_url;

        /** @var ?\Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->getPaymentIntent();
        if (!empty($paymentIntent->next_action->type) && $paymentIntent->next_action->type == "verify_with_microdeposits")
            return $paymentIntent->next_action->verify_with_microdeposits->hosted_verification_url;

        return null;
    }

    public function isLegacyPaymentMethod()
    {
        $transactionId = $this->getTransactionId();
        if (!empty($transactionId) && (strpos($transactionId, "src_") !== false || strpos($transactionId, "ch_") !== false))
            return true;

        return false;
    }

    public function getLatestCharge()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->latest_charge))
            return $paymentIntent->latest_charge;

        return null;
    }

    public function getExpiryDate()
    {
        $charge = $this->getLatestCharge();

        $date = $charge->payment_method_details->card->capture_before ?? null;

        if ($date)
        {
            // Format is 2nd Jan 2023
            $date = date("jS M Y", $date);
            return $date;
        }

        return null;
    }

    public function getInstallmentsPlan($includeLabel = false)
    {
        $info = $this->getInfo();

        if ($info && $info->getAdditionalInformation("selected_installment_plan")) {
            return $this->installmentsHelper->getInstallmentsDetailsFromPayment(
                $info->getAdditionalInformation("selected_installment_plan"),
                $includeLabel
            );
        }

        return false;
    }
}
