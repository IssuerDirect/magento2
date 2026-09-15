<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class SetupIntent
{
    public const ONLINE_ACTIONS = [
        'three_d_secure_redirect',
        'use_stripe_sdk',
        'redirect_to_url'
    ];

    private $config;
    private $helper;
    private $customer;
    private $remoteAddress;
    private $httpHeader;
    private $paymentMethodFactory;
    private $orderHelper;
    private $paymentMethodTypesHelper;
    private $tokenHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $paymentMethodFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\PaymentMethodTypes $paymentMethodTypesHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Header $httpHeader
    ) {
        $this->paymentMethodFactory = $paymentMethodFactory;
        $this->config = $config;
        $this->helper = $helper;
        $this->customer = $helper->getCustomerModel();
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
        $this->orderHelper = $orderHelper;
        $this->paymentMethodTypesHelper = $paymentMethodTypesHelper;
        $this->tokenHelper = $tokenHelper;
    }

    public function getCreateParams($order)
    {
        $description = $this->orderHelper->getOrderDescription($order);

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists(false, $order);
        }

        $params = [
            "use_stripe_sdk" => true,
            "customer" => $this->customer->getStripeId(),
            "description" => $description,
            "metadata" => $this->config->getMetadata($order),
            "confirm" => true,
            "usage" => "off_session",
            "return_url" => $this->helper->getUrl("stripe/payment/index")
        ];

        $paymentMethodTypes = $this->paymentMethodTypesHelper->getPaymentMethodTypes();
        if ($paymentMethodTypes)
        {
            $params["payment_method_types"] = $paymentMethodTypes;
        }
        else
        {
            $params["automatic_payment_methods"] = [ 'enabled' => 'true' ];
        }

        if ($order->getPayment()->getAdditionalInformation("confirmation_token"))
        {
            $params["confirmation_token"] = $order->getPayment()->getAdditionalInformation("confirmation_token");
        }
        else
        {
            $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
            $params["payment_method"] = $paymentMethodId;

            $paymentMethod = $this->paymentMethodFactory->create()->fromPaymentMethodId($paymentMethodId)->getStripeObject();
            $params["mandate_data"] = $this->getMandateData($paymentMethod);
        }

        $customerEmail = $order->getCustomerEmail();
        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        return $params;
    }

    public function getConfirmParams($order)
    {
        $params = [
            "use_stripe_sdk" => true,
            "return_url" => $this->helper->getUrl("stripe/payment/index")
        ];

        if ($order && $order->getPayment()->getAdditionalInformation("confirmation_token"))
        {
            $params["confirmation_token"] = $order->getPayment()->getAdditionalInformation("confirmation_token");
        }
        else
        {
            $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
            $paymentMethod = $this->paymentMethodFactory->create()->fromPaymentMethodId($paymentMethodId)->getStripeObject();

            $params["payment_method"] = $order->getPayment()->getAdditionalInformation("token");
            $params["mandate_data"] = $this->getMandateData($paymentMethod);
        }

        return $params;
    }

    public function getSavePaymentMethodParams($token, $subscriptionId = null)
    {
        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists();
        }

        $params = [
            "use_stripe_sdk" => true,
            "customer" => $this->customer->getStripeId(),
            "confirm" => true,
            "usage" => "off_session",
            "automatic_payment_methods" => [ 'enabled' => 'true' ],
            // "payment_method_data" => [
            //     "allow_redisplay" => "always"
            // ],
            "return_url" => $this->getReturnUrl($subscriptionId)
        ];

        if ($this->tokenHelper->isConfirmationToken($token))
        {
            $params["confirmation_token"] = $token;
            // $confirmationToken = $this->config->getStripeClient()->confirmationTokens->retrieve($token);
            // $type = $confirmationToken->payment_method_preview->type;
            // $params["payment_method_data"]["type"] = $type;
            // $params["payment_method_data"][$type] = [];
        }
        else
        {
            $params["payment_method"] = $token;
            $paymentMethod = $this->paymentMethodFactory->create()->fromPaymentMethodId($token)->getStripeObject();
            $params["mandate_data"] = $this->getMandateData($paymentMethod);
            // $type = $paymentMethod->type;
            // $params["payment_method_data"]["type"] = $type;
            // $params["payment_method_data"][$type] = [];
        }

        return $params;
    }

    private function getReturnUrl($subscriptionId)
    {
        // When the subscription ID is set, the return url will be the change payment method for subscription.
        // Stripe will add the setupIntent id once the confirmation is made, and we will be able to add the payment
        // method on the subscription.
        if ($subscriptionId) {
            return $this->helper->getUrl("stripe/subscriptions/changepaymentmethod", [
                'subscription_id' => $subscriptionId
            ]);
        }

        return $this->helper->getUrl("stripe/customer/paymentmethods");
    }

    public function requiresOnlineAction($setupIntent)
    {
        if ($setupIntent->status == "requires_action"
            && !empty($setupIntent->next_action->type)
            && in_array($setupIntent->next_action->type, self::ONLINE_ACTIONS)
        )
        {
            return true;
        }

        return false;
    }

    private function getMandateData($paymentMethod)
    {
        $remoteAddress = $this->remoteAddress->getRemoteAddress();
        $userAgent = $this->httpHeader->getHttpUserAgent();
        $unsupportedMethods = ['afterpay_clearpay', 'blik'];

        if (!$remoteAddress || !$userAgent || empty($paymentMethod->type) || in_array($paymentMethod->type, $unsupportedMethods))
        {
            return [];
        }

        $mandateData = [
            "customer_acceptance" => [
                "type" => "online",
                "online" => [
                    "ip_address" => $remoteAddress,
                    "user_agent" => $userAgent,
                ]
            ]
        ];

        return $mandateData;
    }

}