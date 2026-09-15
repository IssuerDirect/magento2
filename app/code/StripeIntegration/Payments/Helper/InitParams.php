<?php

namespace StripeIntegration\Payments\Helper;

class InitParams
{
    private $helper;
    private $paymentMethodHelper;
    private $paymentElement;
    private $expressCheckoutConfig;
    private $customer;
    private $localeHelper;
    private $config;
    private $serializer;
    private $quoteHelper;
    private $subscriptionProductFactory;
    private $paymentMethodTypesHelper;
    private $paymentMethodOptionsHelper;
    private $productHelper;
    private $currencyHelper;
    private $addressHelper;

    public function __construct(
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\PaymentMethodTypes $paymentMethodTypesHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\ExpressCheckout\Config $expressCheckoutConfig,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\PaymentMethodOptions $paymentMethodOptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->quoteHelper = $quoteHelper;
        $this->localeHelper = $localeHelper;
        $this->paymentMethodTypesHelper = $paymentMethodTypesHelper;
        $this->productHelper = $productHelper;
        $this->currencyHelper = $currencyHelper;
        $this->expressCheckoutConfig = $expressCheckoutConfig;
        $this->config = $config;
        $this->paymentElement = $paymentElement;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentMethodOptionsHelper = $paymentMethodOptionsHelper;
        $this->customer = $helper->getCustomerModel();
        $this->addressHelper = $addressHelper;
    }

    public function getCheckoutParams()
    {
        if ($this->helper->isMultiShipping()) // Called by the UIConfigProvider
        {
            return $this->getMultishippingParams();
        }
        else
        {
            $params = [
                "apiKey" => $this->config->getPublishableKey(),
                "locale" => $this->localeHelper->getStripeJsLocale(),
                "appInfo" => $this->config->getAppInfo(true),
                "options" => [
                    "betas" => $this->config->getApiBetasClient()
                ],
                "successUrl" => $this->helper->getUrl('stripe/payment/index'),
                "cvcIcon" => $this->paymentMethodHelper->getCVCIcon(),
                "isOrderPlaced" => $this->paymentElement->isOrderPlaced(),
                "externalPaymentMethods" => $this->paymentMethodHelper->getExternalPaymentMethods($this->quoteHelper->getQuote()),
            ];

            $this->setPaymentMethodSelectorLayout($params);

            // Only display the wallets if they are set from the config
            if ($this->expressCheckoutConfig->isEnabled("checkout_page_embedded"))
            {
                $params["wallets"] = null;
            } else {
                $params["wallets"] = [
                    "applePay" => "never",
                    "googlePay" => "never",
                    "link" => "never"
                ];
            }

            $paymentElementTerms = $this->paymentMethodOptionsHelper->getPaymentElementTerms($this->quoteHelper->getQuote());

            if (!empty($paymentElementTerms))
            {
                $params["terms"] = $paymentElementTerms;
            }
        }

        return $this->serializer->serialize($params);
    }

    public function getAPIModuleConfiguration()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => $this->config->getApiBetasClient()
            ],
            'elementsOptions' => $this->serializer->serialize($this->getElementOptions())
        ];

        return $this->serializer->serialize($params);
    }

    public function getAdminParams()
    {
        $params = $this->getAdminPaymentElementParams();

        return $this->serializer->serialize($params);
    }

    public function getMultishippingParams()
    {
        $quote = $this->quoteHelper->getQuote();
        $setupFutureUsage = $this->config->getSetupFutureUsage($quote);

        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
        ];

        if ($setupFutureUsage)
            $params["setupFutureUsage"] = $setupFutureUsage;

        $this->setPaymentMethodSelectorLayout($params);

        return $this->serializer->serialize($params);
    }

    public function getMyPaymentMethodsParams()
    {
        if (!$this->config->isEnabled())
            return $this->serializer->serialize([]);

        $paymentElementFeatures = [
            'payment_method_redisplay' => 'disabled',
            'payment_method_save' => 'disabled',
//            'payment_method_save_usage' => 'off_session',
            'payment_method_remove' => 'disabled',
        ];
        $params = $this->buildPaymentMethodsParams($paymentElementFeatures);
        $params["returnUrl"] = $this->helper->getUrl('stripe/customer/paymentmethods');
        $params["layout"] = [
            "type" => "tabs"
        ];

        return $this->serializer->serialize($params);
    }

    public function getNewSubscriptionPaymentMethodParams()
    {
        if (!$this->config->isEnabled())
            return $this->serializer->serialize([]);

        $paymentElementFeatures = [
            'payment_method_redisplay' => 'enabled',
            'payment_method_save' => 'disabled',
//            'payment_method_save_usage' => 'off_session',
            'payment_method_remove' => 'disabled',
            'payment_method_redisplay_limit' => 10
        ];
        $params = $this->buildPaymentMethodsParams($paymentElementFeatures);
        $params["returnUrl"] = $this->helper->getUrl('stripe/customer/subscriptions', ['paymentMethodUpdateSuccess' => true]);
        $params["layout"] = [
            "type" => "accordion",
            "radios" => "always",
            "spacedAccordionItems" => false,
            "visibleAccordionItemsCount" => 0
        ];

        return $this->serializer->serialize($params);
    }

    // Used in non-checkout contexts, such as the My Payment Methods page and the New Subscription Payment Method page
    private function buildPaymentMethodsParams($paymentElementFeatures): array
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "currency" => strtolower($this->currencyHelper->getCurrentCurrencyCode()),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => $this->config->getApiBetasClient()
            ],
            "elementsOptions" => [
                "mode" => "setup",
                "setupFutureUsage" => "off_session",
                "locale" => $this->localeHelper->getStripeJsLocale(),
                "currency" => strtolower($this->currencyHelper->getCurrentCurrencyCode()),
                "appearance" => [
                  "theme" => "stripe",
                  "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                  ],
                ],
            ]
        ];

        if (!$this->customer->existsInStripe())
            $this->customer->createStripeCustomerIfNotExists();

        $customerSession = $this->config->getStripeClient()->customerSessions->create([
            'customer' => $this->customer->getStripeId(),
            'components' => [
                'payment_element' => [
                    'enabled' => true,
                    'features' => $paymentElementFeatures,
                ],
            ],
        ]);

        $params['elementsOptions']['customerSessionClientSecret'] = $customerSession->client_secret;

        return $params;
    }

    public function getWalletParams()
    {
        $params = $this->getStripeParams();

        return $this->serializer->serialize($params);
    }

    private function getStripeParams()
    {
        return [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => $this->config->getApiBetasClient()
            ]
        ];
    }

    public function getAdminPaymentElementParams()
    {
        $params = [
            'stripeInitParams' => $this->getStripeInitParams(),
            'initElementsParams' => $this->getInitElementsParams(),
            'paymentElementOptions' => $this->getAdminPaymentElementOptions()
        ];
        return $params;
    }

    public function getStripeInitParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => $this->config->getApiBetasClient()
            ],
        ];

        return $params;
    }

    public function getInitElementsParams()
    {
        $quote = $this->quoteHelper->getQuote();
        $currency = ($quote && $quote->getQuoteCurrencyCode()) ? $quote->getQuoteCurrencyCode() : $this->currencyHelper->getCurrentCurrencyCode();
        $amount = ($quote && $quote->getGrandTotal()) ? $quote->getGrandTotal() : 0;
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);

        $params = [
            "paymentMethodCreation" => "manual",
            "mode" => "payment",
            "currency" => strtolower($currency),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "payment_method_types" => ["card"],
            'appearance' => [
                "theme" => "stripe",
                "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif'
                ],
            ],
        ];

        if ($stripeAmount > 0) {
            $params["amount"] = $stripeAmount;
        }

        if ($this->config->getPaymentAction() == "order")
        {
            $params["mode"] = "setup";
            $params["setupFutureUsage"] = "off_session";
            unset($params["amount"]);
        }

        if ($this->config->isEnabled() && $this->config->isSubscriptionsEnabled())
        {
            if ($this->quoteHelper->hasSubscriptions())
            {
                // Regular products may also exist in this cart. We still go for subscribe mode.
                $params["mode"] = "subscription";
            }
        }

        return $params;
    }

    public function getAdminPaymentElementOptions()
    {
        $options = [
            'wallets' => [
                'applePay' => 'never',
                'googlePay' => 'never',
                'link' => 'never',
            ],
            'fields' => [
                'billingDetails' => [
                    'address' => [
                        'line1' => 'never',
                        'line2' => 'never',
                        'city' => 'never',
                        'country' => 'never',
                        'postalCode' => 'never',
                    ],
                    'name' => 'never',
                    'phone' => 'never',
                    'email' => 'never',
                ]
            ],
        ];

        return $options;
    }

    // Used to initialize the Elements object at the checkout page
    public function getElementOptions()
    {
        $quote = $this->quoteHelper->getQuote();
        $currency = ($quote && $quote->getQuoteCurrencyCode()) ? $quote->getQuoteCurrencyCode() : $this->currencyHelper->getCurrentCurrencyCode();
        $amount = ($quote && $quote->getGrandTotal()) ? $quote->getGrandTotal() : 0;
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);

        $options = [
            "mode" => "payment",
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "currency" => strtolower($currency),
            "appearance" => [
                "theme" => "stripe",
                "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif'
                ],
            ]
        ];

        if ($stripeAmount > 0)
            $options["amount"] = $stripeAmount;

        if ($this->config->getPaymentAction() == "order")
        {
            $options["mode"] = "setup";
            unset($options["amount"]);
        }

        if ($this->config->isEnabled() && $this->config->isSubscriptionsEnabled())
        {
            if ($this->quoteHelper->hasSubscriptions())
            {
                // Regular products may also exist in this cart. We still go for subscribe mode.
                $options["mode"] = "subscription";
            }
        }

        $paymentMethodTypes = $this->paymentMethodTypesHelper->getPaymentMethodTypes();
        $pmc = $this->config->getPaymentMethodConfiguration();

        if ($options["mode"] == "payment" && $paymentMethodTypes)
        {
            $options["paymentMethodTypes"] = $paymentMethodTypes;
        }
        else if ($pmc)
        {
            $options['paymentMethodConfiguration'] = $pmc;
        }

        if ($this->config->hasCustomerSessionFeatures($quote))
        {
            $features = $this->getFeatures($quote);
            $this->customer->createStripeCustomerIfNotExists();

            $customerSession = $this->config->getStripeClient()->customerSessions->create([
                'customer' => $this->customer->getStripeId(),
                'components' => [
                    'payment_element' => [
                        'enabled' => true,
                        'features' => $features,
                    ],
                ],
            ]);

            $options['customerSessionClientSecret'] = $customerSession->client_secret;

            if ($this->config->reCheckCVCForSavedCards() && $options["mode"] != "setup")
            {
                $options['paymentMethodOptions'] = [
                    'card' => [
                        'require_cvc_recollection' => true
                    ]
                ];
            }
        }

        return $options;
    }

    public function getFeatures($quote = null)
    {
        $askToSave = $this->config->getSavePaymentMethod() == 1;
        $alwaysSave = $this->config->alwaysSavePaymentMethod();
        $features = [];

        if ($alwaysSave)
        {
            $features = [
                'payment_method_redisplay' => 'enabled',
                'payment_method_save' => 'disabled', // disabled at the front-end, we always save the payment method server-side
                'payment_method_remove' => 'enabled'
            ];
        }
        else if ($askToSave)
        {
            $setupFutureUsage = $this->config->getSetupFutureUsage($quote);
            $features = [
                'payment_method_redisplay' => 'enabled',
                'payment_method_remove' => 'enabled',
                'payment_method_save' => 'enabled',
                // DO NOT move the fallback 'on_session' into getSetupFutureUsage, otherwise the payment method will be saved
                // regardless of explicit user consent at the front-end
                'payment_method_save_usage' => $setupFutureUsage ?? 'on_session'
            ];
        }

        return $features;
    }

    public function getExpressCheckoutElementsOptions($resolvePayload, $viewingProductId = null)
    {
        $quote = $this->quoteHelper->getQuote();
        $currency = ($quote && $quote->getQuoteCurrencyCode()) ? $quote->getQuoteCurrencyCode() : $this->currencyHelper->getCurrentCurrencyCode();

        if (!empty($resolvePayload['lineItems']))
        {
            $stripeAmount = 0;
            foreach ($resolvePayload['lineItems'] as $item)
            {
                $stripeAmount += $item['amount'];
            }
        }
        else
        {
            $amount = ($quote && $quote->getGrandTotal()) ? $quote->getGrandTotal() : 0;
            $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        }

        $options = [
            "mode" => $this->getECEMode($viewingProductId),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            // "paymentMethodTypes" => $this->paymentMethodTypesHelper->getPaymentMethodTypes($isExpressCheckout = true),
            "appearance" => [
                "theme" => "stripe",
                "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif'
                ],
            ],
            "currency" => strtolower($currency)
        ];

        if ($options["mode"] == "setup")
        {
            $options["setup_future_usage"] = "off_session";
        }
        else
        {
            $options["amount"] = $stripeAmount;
        }

        return $options;
    }

    public function getExpressCheckoutWalletOptions($quote, $viewingProductId, $location)
    {
        if (is_numeric($viewingProductId))
        {
            $shippingAddressRequired = true;
        }
        else
        {
            $quoteHasItems = $quote && count($quote->getAllVisibleItems()) > 0;
            $shippingAddressRequired = ($quoteHasItems && !$quote->isVirtual());

            if ($location == "checkout" && $quote && $this->addressHelper->quoteHasCompleteShippingAddress($quote))
            {
                $shippingAddressRequired = false;
            }
        }

        return [
            'allowedShippingCountries' => $this->addressHelper->getAllowedShippingCountries(),
            'billingAddressRequired' => true,
            'emailRequired' => true,
            'phoneNumberRequired' => true,
            'shippingAddressRequired' => $shippingAddressRequired
        ];
    }

    public function getECEMode($viewingProductId)
    {
        $viewingSubscriptionProduct = $this->_isSubscriptionProduct($viewingProductId);
        return $this->config->getECEMode($viewingSubscriptionProduct);
    }

    private function _isSubscriptionProduct($productId)
    {
        if (!is_numeric($productId))
            return false;

        if ($this->subscriptionProductFactory->create()->fromProductId($productId)->isSubscriptionProduct())
            return true;

        try
        {
            $product = $this->productHelper->getProduct($productId);
        }
        catch (\Exception $e)
        {
            return false;
        }

        // If it is a configurable product, and any of its configurable options is a subscription product, return true
        if ($product && $product->getTypeId() == "configurable")
        {
            $children = $product->getTypeInstance()->getUsedProducts($product);
            foreach ($children as $child)
            {
                if ($this->subscriptionProductFactory->create()->fromProductId($child->getEntityId())->isSubscriptionProduct())
                    return true;
            }
        }

        // If it is a bundle product, and any of its bundle options is a subscription product, return true
        if ($product && $product->getTypeId() == "bundle")
        {
            $bundleType = $product->getTypeInstance();

            // Load the selections (sub-products) for the bundle product
            $optionIds = $bundleType->getOptionsIds($product);
            $selections = $bundleType->getSelectionsCollection($optionIds, $product);

            foreach ($selections as $selection)
            {
                $productId = $selection->getProductId();
                if ($this->subscriptionProductFactory->create()->fromProductId($productId)->isSubscriptionProduct())
                {
                    return true;
                }
            }
        }

        return false;
    }

    public function setPaymentMethodSelectorLayout(&$params)
    {
        if ($this->config->isVerticalLayout())
        {
            $params["layout"] = [
                "type" => "accordion",
                "defaultCollapsed" => false,
                "radios" => "always",
                "spacedAccordionItems" => false,
                "visibleAccordionItemsCount" => 0
            ];
        }
        else
        {
            $params["layout"] = [
                "type" => "tabs"
            ];
        }
    }

    public function geECEConfiguration($location)
    {
        $enabled = $this->expressCheckoutConfig->isEnabled($location);
        $configuration = [
            'enabled' => $enabled
        ];

        // Only add the other components if the ECE is enabled at the location
        if ($enabled) {
            $configuration['initParams'] = $this->getStripeParams();
            $configuration['buttonConfig'] = $this->expressCheckoutConfig->getButtonOptions();
        }

        return $this->serializer->serialize($configuration);
    }
}
