<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class PaymentIntent
{
    public const ONLINE_ACTIONS = [
        'three_d_secure_redirect',
        'use_stripe_sdk',
        'redirect_to_url'
    ];

    public const CANCELABLE_STATUSES = [
        'requires_payment_method',
        'requires_capture',
        'requires_confirmation',
        'requires_action',
        'requires_source',
        'processing'
    ];

    private $remoteAddress;
    private $httpHeader;
    private $quoteHelper;
    private $stripePaymentMethodFactory;
    private $urlHelper;
    private $paymentMethodOptionsHelper;
    private $config;
    private $invoicePaymentsHelper;
    private $installmentsHelper;
    private $tokenHelper;
    private $confirmationTokenFactory;

    public function __construct(
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Header $httpHeader,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\PaymentMethodOptions $paymentMethodOptionsHelper,
        \StripeIntegration\Payments\Helper\Installments $installmentsHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Model\Stripe\ConfirmationTokenFactory $confirmationTokenFactory
    )
    {
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
        $this->quoteHelper = $quoteHelper;
        $this->urlHelper = $urlHelper;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->config = $config;
        $this->paymentMethodOptionsHelper = $paymentMethodOptionsHelper;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->installmentsHelper = $installmentsHelper;
        $this->tokenHelper = $tokenHelper;
        $this->confirmationTokenFactory = $confirmationTokenFactory;
    }

    public function getConfirmParams($order, $paymentIntent)
    {
        $confirmParams = [
            "use_stripe_sdk" => true
        ];

        // Prioritize confirmation token when available
        if ($order->getPayment()->getAdditionalInformation("confirmation_token"))
        {
            $confirmParams["confirmation_token"] = $order->getPayment()->getAdditionalInformation("confirmation_token");
        }
        else if ($order->getPayment()->getAdditionalInformation("token"))
        {
            // Fallback to payment method token for backwards compatibility
            $confirmParams["payment_method"] = $order->getPayment()->getAdditionalInformation("token");
            $paymentMethod = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($confirmParams['payment_method'])->getStripeObject();
            $mandateData = $this->getMandateData($paymentMethod, $paymentIntent);
            if (!empty($mandateData))
            {
                $confirmParams = array_merge($confirmParams, $mandateData);
            }
        }

        $confirmParams["return_url"] = $this->urlHelper->getUrl('stripe/payment/index');

        $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());
        $options = $this->paymentMethodOptionsHelper->getPaymentMethodOptions($quote);

        if (!empty($confirmParams["confirmation_token"]))
        {
            $confirmationToken = $this->confirmationTokenFactory->create()->fromId(
                $confirmParams["confirmation_token"]
            );

            $confirmationTokenSetupFutureUsage = $confirmationToken->getSetupFutureUsage();
            if (!$confirmationTokenSetupFutureUsage && $paymentIntent->setup_future_usage)
            {
                // The customer failed a payment with "Save payment details" checked, and then retried
                // with it unchecked. We clear the setup_future_usage so that the payment method is not saved.
                $confirmParams['setup_future_usage'] = null;
            }
        }

        if (!empty($options))
        {
            $confirmParams["payment_method_options"] = $options;
        }

        if ($this->config->isMsiEnabled() &&
            $order->getPayment()->getAdditionalInformation("selected_installment_plan")
        ) {
            $plan = $this->installmentsHelper->getDecodedInstallmentsPlan(
                $order->getPayment()->getAdditionalInformation("selected_installment_plan")
            );
            // Set the installments plan only if it is not the one 'one payment' option
            if (!$this->installmentsHelper->isOnePayment($plan)) {
                $confirmParams["payment_method_options"]["card"]["installments"]["plan"] = $plan;
            }
        }

        return $confirmParams;
    }

    // Only used when manually capturing payments from the admin area
    public function getAdminConfirmParams($order, $paymentIntent)
    {
        $params = $this->getConfirmParams($order, $paymentIntent);

        if (isset($params['payment_method_options']))
        {
            // We don't want to authorize only and we don't want to setup future usage
            unset($params["payment_method_options"]);
        }

        return $params;
    }

    public function getMultishippingConfirmParams($token, $paymentIntent, $quote = null)
    {
        $setupFutureUsage = $this->config->getSetupFutureUsage($quote);

        if ($this->tokenHelper->isPaymentMethodToken($token))
        {
            $confirmParams = [
                'payment_method' => $token,
                'use_stripe_sdk' => true,
            ];

            if ($setupFutureUsage)
                $confirmParams['setup_future_usage'] = $setupFutureUsage;

            $paymentMethod = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($token)->getStripeObject();
            $mandateData = $this->getMandateData($paymentMethod, $paymentIntent);
            if (!empty($mandateData))
            {
                $confirmParams = array_merge($confirmParams, $mandateData);
            }
        }
        else if ($this->tokenHelper->isConfirmationToken($token))
        {
            $confirmParams = [
                'confirmation_token' => $token,
                'use_stripe_sdk' => true,
            ];

            if ($setupFutureUsage)
                $confirmParams['setup_future_usage'] = $setupFutureUsage;
        }
        else
        {
            throw new LocalizedException(__("Invalid token provided for multishipping payment confirmation."));
        }

        if (!empty($paymentIntent->automatic_payment_methods->enabled))
            $confirmParams["return_url"] = $this->urlHelper->getUrl('stripe/payment/index');

        if ($quote)
        {
            $options = $this->paymentMethodOptionsHelper->getPaymentMethodOptions($quote);
            if (!empty($options))
            {
                $confirmParams["payment_method_options"] = $options;
            }
        }

        return $confirmParams;
    }

    public function isSuccessful($paymentIntent)
    {
        if ($paymentIntent->status == "processing" && !$this->isAsyncProcessing($paymentIntent))
        {
            // https://stripe.com/docs/payments/paymentintents/lifecycle#intent-statuses
            return true;
        }
        else if (in_array($paymentIntent->status, ['succeeded', 'requires_capture']))
        {
            return true;
        }

        return false;
    }

    // For payment methods which are synchronous such as cards and link, this will return false event if they are in Processing status
    // https://stripe.com/docs/payments/paymentintents/lifecycle#intent-statuses
    public function isAsyncProcessing($paymentIntent)
    {
        if ($paymentIntent->status == "processing" && (empty($paymentIntent->processing->type) || $paymentIntent->processing->type != "card"))
        {
            return true;
        }

        return false;
    }

    public function isProcessing($paymentIntent)
    {
        return $paymentIntent->status == "processing";
    }

    public function requiresOfflineAction($paymentIntent)
    {
        if ($paymentIntent->status == "requires_action" && !$this->requiresOnlineAction($paymentIntent))
        {
            return true;
        }

        return false;
    }

    public function requiresOnlineAction($paymentIntent)
    {
        if ($paymentIntent->status == "requires_action" &&
            !empty($paymentIntent->next_action->type) && (
                in_array($paymentIntent->next_action->type, self::ONLINE_ACTIONS) ||
                strpos($paymentIntent->next_action->type, "_handle_redirect") !== false ||
                strpos($paymentIntent->next_action->type, "_display_qr_code") !== false
            )
        )
        {
            return true;
        }

        return false;
    }

    public function isUnconfirmed($paymentIntent)
    {
        return in_array($paymentIntent->status, ["requires_confirmation", "requires_payment_method"]);
    }

    public function canCancel($paymentIntent)
    {
        return in_array($paymentIntent->status, self::CANCELABLE_STATUSES)
            && !$this->invoicePaymentsHelper->getInvoiceFromPaymentIntentId($paymentIntent->id); // Subscription PIs cannot be canceled
    }

    public function canConfirm($paymentIntent)
    {
        return $this->isUnconfirmed($paymentIntent);
    }

    public function isSetupIntent($id)
    {
        if (!empty($id) && strpos($id, "seti_") === 0)
            return true;

        return false;
    }

    protected function hasFinalizedInvoice($paymentIntent)
    {
        $invoice = $this->invoicePaymentsHelper->getInvoiceFromPaymentIntentId($paymentIntent->id);
        if (!$invoice)
            return false;

        if ($invoice->status == 'open')
            return false;

        return true;
    }

    public function getUpdateableParams($params, $paymentIntent = null)
    {
        if (($paymentIntent && (
                $this->isSuccessful($paymentIntent) ||
                $this->isAsyncProcessing($paymentIntent) ||
                $this->requiresOfflineAction($paymentIntent)) ||
                $this->isSetupIntent($paymentIntent->id)
            )
            || $this->hasFinalizedInvoice($paymentIntent))
        {
            $updateableParams = [
                "description",
                "metadata"
            ];
        }
        else
        {
            $updateableParams = [
                "amount",
                "description",
                "metadata",
                "setup_future_usage",
                "shipping" // Required by certain methods like AfterPay/Clearpay
            ];

            $invoice = $this->invoicePaymentsHelper->getInvoiceFromPaymentIntentId($paymentIntent->id);
            if (empty($invoice))
            {
                $updateableParams[] = "currency";
                $updateableParams[] = "amount_details";
                $updateableParams[] = "payment_details";
            }

            // We can only set the customer, we cannot change it
            if (!empty($params["customer"]) && empty($paymentIntent->customer))
            {
                $updateableParams[] = "customer";
            }
        }

        $nonEmptyParams = [];

        foreach ($updateableParams as $paramName)
        {
            if (!empty($params[$paramName]))
                $nonEmptyParams[] = $paramName;
        }

        return $nonEmptyParams;
    }

    public function getFilteredParamsForUpdate($params, $paymentIntent = null)
    {
        $newParams = [];

        foreach ($this->getUpdateableParams($params, $paymentIntent) as $key)
        {
            if (isset($params[$key]))
                $newParams[$key] = $params[$key];
            else
                $newParams[$key] = null; // Unsets it through the API
        }

        return $newParams;
    }

    public function getMandateData($paymentMethod, $intent): array
    {
        $params = [];
        $remoteAddress = $this->remoteAddress->getRemoteAddress();
        $userAgent = $this->httpHeader->getHttpUserAgent();
        $unsupportedMethods = [
            'afterpay_clearpay',
            'blik',
            'kr_card',
            'kakao_pay',
            'samsung_pay',
            'naver_pay',
            'payco'
        ];

        if (!$remoteAddress || !$userAgent || empty($paymentMethod->type) || in_array($paymentMethod->type, $unsupportedMethods))
        {
            return [];
        }

        $params['mandate_data']['customer_acceptance'] = [
            "type" => "online",
            "online" => [
                "ip_address" => $remoteAddress,
                "user_agent" => $userAgent,
            ]
        ];

        return $params;
    }

    /**
     * Checks if a payment intent is for an unpaid bank transfer
     *
     * @param $paymentIntent
     * @return bool
     */
    public function isBankTransferUnpaid($paymentIntent)
    {
        // The payment method type needs to be customer balance and the status needs to require action
        if (!in_array("customer_balance", $paymentIntent->payment_method_types)) {
            return false;
        }

        if ($paymentIntent->status != "requires_action") {
            return false;
        }

        // The next action will have to be to display the bank transfer details (once the PI is paid, there is no next_action)
        if (empty($paymentIntent->next_action->type) || $paymentIntent->next_action->type != "display_bank_transfer_instructions") {
            return false;
        }

        return true;
    }
}
