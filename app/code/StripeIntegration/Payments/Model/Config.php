<?php

namespace StripeIntegration\Payments\Model;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\GenericException;

class Config
{
    public static $moduleName           = "Magento2";
    public static $moduleVersion        = "4.6.6";
    public static $minStripePHPVersion  = "20.1.0";
    private static $moduleUrl           = "https://docs.stripe.com/use-stripe-apps/adobe-commerce";
    private static $partnerId           = "pp_partner_Fs67gT2M6v3mH7";

    // State
    private $isInitialized;
    public $isSubscriptionsEnabled      = null;
    private $stripeClient               = null;

    // Constructor properties
    private $encryptor;
    private $logger;
    private $storeManager;
    private $stripeCustomerCollection;
    private $taxConfig;
    private $webhookCollection;
    private $storeRepository;
    private $productMetadata;
    private $cacheTypeList;
    private $appState;
    private $isStripeAPIKeyError;
    private $resourceConfig;
    private $scopeConfig;
    private $quoteHelper;
    private $accountCollectionFactory;
    private $accountFactory;
    private $configHelper;
    private $loggerHelper;
    private $areaCodeHelper;
    private $storeHelper;
    private $cartInfo;
    private $checkoutFlow;
    private $subscriptionFlow;
    private $stripePaymentMethodFactory;
    private $tokenHelper;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Config\Model\ResourceModel\Config $resourceConfig,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \StripeIntegration\Payments\Model\ResourceModel\StripeCustomer\Collection $stripeCustomerCollection,
        \Magento\Tax\Model\Config $taxConfig,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhookCollection,
        \Magento\Store\Api\StoreRepositoryInterface $storeRepository,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\App\State $appState,
        \StripeIntegration\Payments\Model\ResourceModel\Account\CollectionFactory $accountCollectionFactory,
        \StripeIntegration\Payments\Model\AccountFactory $accountFactory,
        \StripeIntegration\Payments\Model\Cart\Info $cartInfo,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Store $storeHelper,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\Subscription\Flow $subscriptionFlow
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->loggerHelper = $loggerHelper;
        $this->quoteHelper = $quoteHelper;
        $this->configHelper = $configHelper;
        $this->encryptor = $encryptor;
        $this->resourceConfig = $resourceConfig;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->stripeCustomerCollection = $stripeCustomerCollection;
        $this->taxConfig = $taxConfig;
        $this->webhookCollection = $webhookCollection;
        $this->storeRepository = $storeRepository;
        $this->productMetadata = $productMetadata;
        $this->cacheTypeList = $cacheTypeList;
        $this->appState = $appState;
        $this->accountCollectionFactory = $accountCollectionFactory;
        $this->accountFactory = $accountFactory;
        $this->cartInfo = $cartInfo;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->storeHelper = $storeHelper;
        $this->checkoutFlow = $checkoutFlow;
        $this->subscriptionFlow = $subscriptionFlow;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->tokenHelper = $tokenHelper;

        $this->initStripe();
    }

    public function getApiVersion()
    {
        $apiVersion = $this->scopeConfig->getValue('stripe_settings/api/version', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);

        return $apiVersion;
    }

    public function getApiBetasServer()
    {
        $betas = $this->scopeConfig->getValue('stripe_settings/api/betas_server', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);
        if (empty($betas))
            return [];

        return array_map('trim', explode(",", $betas));
    }

    public function getApiBetasClient()
    {
        $betas = $this->scopeConfig->getValue('stripe_settings/api/betas_client', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);
        if (empty($betas))
            return [];

        return array_map('trim', explode(",", $betas));
    }

    public function getComposerRequireVersion()
    {
        $version = explode(".", \StripeIntegration\Payments\Model\Config::$minStripePHPVersion);
        array_pop($version);
        return implode(".", $version);
    }

    public function canInitialize(&$error = null)
    {
        if (!class_exists('Stripe\Stripe'))
        {
            $error = "The Stripe PHP library dependency has not been installed. Please follow the installation instructions at https://docs.stripe.com/use-stripe-apps/adobe-commerce/payments/install?integration=package";
            $this->logger->critical($error);
            return false;
        }

        if (version_compare(\Stripe\Stripe::VERSION, \StripeIntegration\Payments\Model\Config::$minStripePHPVersion) < 0)
        {
            $version = \StripeIntegration\Payments\Model\Config::$moduleVersion;
            $libVersion = $this->getComposerRequireVersion();
            $error = "Stripe Payments v$version depends on Stripe PHP library v$libVersion or newer. Please upgrade your installed Stripe PHP library with the command: composer require stripe/stripe-php:^$libVersion";
            $this->logger->critical($error);
            return false;
        }

        return true;
    }

    public function isInitialized()
    {
        if (!isset($this->isInitialized))
            return false;

        return $this->isInitialized;
    }

    public function initStripe($mode = null, $storeId = null)
    {
        if ($this->isInitialized())
            return true;

        if (!$this->canInitialize())
            return false;

        if ($this->getSecretKey($mode, $storeId) && $this->getPublishableKey($mode, $storeId))
        {
            $key = $this->getSecretKey($mode, $storeId);
            return $this->initStripeFromSecretKey($key);
        }

        return false;
    }

    public function getStripeAPIVersion()
    {
        $api = $this->getApiVersion();

        if (!empty($this->getApiBetasServer()))
        {
            $api .= "; " . implode("; ", $this->getApiBetasServer());
        }

        return $api;
    }

    public function getPaymentMethodConfiguration()
    {
        $storeId = $this->storeHelper->getStoreId();
        $quote = $this->quoteHelper->getQuote();
        $this->cartInfo->setQuote($quote);

        $allCarts = $pmc = $this->scopeConfig->getValue("payment/stripe_payments/pmc_all_carts", ScopeInterface::SCOPE_STORE, $storeId);
        $virtualCarts = $this->scopeConfig->getValue("payment/stripe_payments/pmc_virtual_carts", ScopeInterface::SCOPE_STORE, $storeId);
        $hasGiftCards = $this->cartInfo->hasGiftCards();

        if (($hasGiftCards || $quote->getIsVirtual()) && !empty($virtualCarts))
        {
            $pmc = $virtualCarts;
        }
        else if (!empty($allCarts))
        {
            $pmc = $allCarts;
        }
        else
        {
            return null;
        }

        try
        {
            $paymentMethodConfiguration = $this->getStripeClient()->paymentMethodConfigurations->retrieve($pmc);
            if (!$paymentMethodConfiguration->active)
                return null;
            else
                return $pmc;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function reInitStripeFromSecretKey($key)
    {
        unset($this->isInitialized);
        return $this->initStripeFromSecretKey($key);
    }

    public function initStripeFromSecretKey($key)
    {
        if (!$this->canInitialize())
            return $this->isInitialized = false;

        if (empty($key))
            return $this->isInitialized = false;

        if (isset($this->isInitialized))
            return $this->isInitialized;

        try
        {
            $this->stripeClient = new \Stripe\StripeClient([
                "api_key" => $key,
                "stripe_version" => $this->getStripeAPIVersion(),
                "app_info" => $this->getAppInfo(),
                "max_network_retries" => 2
            ]);

            $accountModel = $this->getAccountModel($key);
            if (!$accountModel->isValid())
            {
                $this->loggerHelper->logError("Invalid secret Stripe API keys.");
                return $this->isInitialized = false;
            }
        }
        catch (\Exception $e)
        {
            $this->loggerHelper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->isInitialized = false;
        }

        return $this->isInitialized = true;
    }

    public function getAppInfo($clientSide = false)
    {
        $magentoVersion = "unknown";
        $magentoEdition = "unknown";

        try
        {
            $magentoVersion = $this->productMetadata->getVersion();
            $magentoEdition = $this->productMetadata->getEdition();
        }
        catch (\Exception $e)
        {

        }

        return [
            "name" => $this::$moduleName,
            "version" => ($clientSide ? $this::$moduleVersion : "{$this::$moduleVersion}_{$magentoVersion}_{$magentoEdition}"),
            "url" => $this::$moduleUrl,
            "partner_id" => $this::$partnerId
        ];
    }

    protected function initStripeFromPublicKey($key)
    {
        $secretKey = null;
        $stores = $this->storeManager->getStores();
        $configurations = [];

        foreach ($stores as $storeId => $store)
        {
            $testKeys = $this->getStoreViewAPIKey($store, 'test');
            if (!empty($testKeys['api_keys']['pk']) && $testKeys['api_keys']['pk'] == $key)
            {
                $secretKey = $testKeys['api_keys']['sk'];
                break;
            }

            $liveKeys = $this->getStoreViewAPIKey($store, 'live');
            if (!empty($liveKeys['api_keys']['pk']) && $liveKeys['api_keys']['pk'] == $key)
            {
                $secretKey = $liveKeys['api_keys']['sk'];
                break;
            }
        }

        return $this->initStripeFromSecretKey($secretKey);
    }

    public function reInitStripe($storeId, $currencyCode, $mode)
    {
        unset($this->isInitialized);
        $this->storeManager->setCurrentStore($storeId);
        $this->storeManager->getStore()->setCurrentCurrencyCode($currencyCode);
        return $this->initStripe($mode);
    }

    public function reInitStripeFromCustomerId($customerId)
    {
        $customer = $this->stripeCustomerCollection->getByStripeCustomerId($customerId);
        if (!$customer)
            return false;

        if (!$customer->getPk())
            return false;

        unset($this->isInitialized);
        return $this->initStripeFromPublicKey($customer->getPk());
    }

    public function reInitStripeFromStoreCode($storeCode, $mode = null)
    {
        $store = $this->storeRepository->getActiveStoreByCode($storeCode);
        if (!$store->getId())
            throw new GenericException("Could not find a store with code '$storeCode'");

        $storeId = $store->getStoreId();
        unset($this->isInitialized);
        $this->storeManager->setCurrentStore($storeId);

        if (!$mode)
            $mode = $this->getStripeMode($storeId);

        return $this->initStripe($mode, $storeId);
    }

    public function reInitStripeFromStoreId($storeId, $mode = null)
    {
        $store = $this->storeRepository->getActiveStoreById($storeId);
        if (!$store->getId())
            throw new GenericException("Could not find a store with id '$storeId'");

        unset($this->isInitialized);
        $this->storeManager->setCurrentStore($storeId);

        if (!$mode)
            $mode = $this->getStripeMode($storeId);

        return $this->initStripe($mode, $storeId);
    }

    public function getConfigData($field, $method = null, $storeId = null)
    {
        if (empty($storeId))
            $storeId = $this->storeHelper->getStoreId();

        $section = "";
        if ($method)
            $section = "_$method";

        return $this->configHelper->getConfigData("payment/stripe_payments$section/$field", $storeId);
    }

    public function getValue($configPath, $scope, $storeId = null)
    {
        return $this->scopeConfig->getValue($configPath, $scope, $storeId);
    }

    public function setConfigData($field, $value, $method = null, $scope = null, $storeId = null)
    {
        if (empty($storeId))
            $storeId = $this->storeHelper->getStoreId();

        if (empty($scope))
            $scope = ScopeInterface::SCOPE_STORE;

        $section = "";
        if ($method)
            $section = "_$method";

        return $this->configHelper->setConfigData("payment/stripe_payments$section/$field", $value, $scope, $storeId);
    }

    public function isSubscriptionsEnabled($storeId = null)
    {
        if ($this->isSubscriptionsEnabled !== null)
            return $this->isSubscriptionsEnabled;

        $this->isSubscriptionsEnabled = ((bool)$this->getConfigData('active', 'subscriptions', $storeId)) && $this->initStripe();
        return $this->isSubscriptionsEnabled;
    }

    public function isEnabled()
    {
        return ((bool)$this->getConfigData('active') && $this->initStripe());
    }

    public function isReceiptEmailsEnabled()
    {
        return ((bool)$this->getConfigData('receipt_emails'));
    }

    public function isTaxCalculationAppliedAfterDiscount()
    {
        if ($this->isStripeTaxEnabled())
        {
            return true;
        }

        $store = $this->storeHelper->getStore();
        return (bool)$store->getConfig('tax/calculation/apply_after_discount');
    }

    public function isTaxCalculationAppliedBeforeDiscount()
    {
        return !$this->isTaxCalculationAppliedAfterDiscount();
    }

    public function getStripeMode($storeId = null)
    {
        return $this->getConfigData('stripe_mode', 'basic', $storeId);
    }

    public function getSecretKey($mode = null, $storeId = null)
    {
        if (empty($mode))
            $mode = $this->getStripeMode($storeId);

        $key = $this->getConfigData("stripe_{$mode}_sk", "basic", $storeId);

        return $this->decrypt($key);
    }

    public function decrypt($key)
    {
        if (empty($key))
            return null;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $key))
            $key = $this->encryptor->decrypt($key);

        if (empty($key))
            return null;

        return trim($key);
    }

    public function getPublishableKey($mode = null, $storeId = null)
    {
        if (empty($mode))
            $mode = $this->getStripeMode();

        $pk = $this->getConfigData("stripe_{$mode}_pk", "basic", $storeId);

        if (empty($pk))
            return null;

        return trim($pk);
    }

    public function getCaptureMethod()
    {
        if ($this->isAuthorizeOnly())
            return "manual";

        return "automatic";
    }

    public function isWebhookOriginCheckEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue("payment/stripe_payments/webhook_origin_check");
    }

    public function getWebhooksSigningSecrets()
    {
        if (!$this->isWebhookOriginCheckEnabled())
            return [];

        $secrets = [];
        $webhooks = $this->webhookCollection->getAllWebhooks();
        foreach ($webhooks as $webhook)
        {
            $secret = $webhook->getSecret();
            if (!empty($secret))
                $secrets[] = $secret;
        }

        return $secrets;
    }

    public function isAutomaticInvoicingEnabled()
    {
        return (bool)$this->getConfigData("automatic_invoicing");
    }

    // If the module is unconfigured, payment_action will be null, defaulting to authorize & capture
    // so this would still return the correct value
    public function isAuthorizeOnly()
    {
        return (
            $this->getPaymentAction() == "authorize"
            && !$this->quoteHelper->hasSubscriptions()
        );
    }

    public function getPaymentAction()
    {
        return $this->getConfigData('payment_action');
    }

    public function isStripeRadarEnabled()
    {
        return ($this->getConfigData('radar_risk_level') > 0);
    }

    public function getSavePaymentMethod()
    {
        return $this->getConfigData('save_payment_method');
    }

    public function getStatementDescriptor()
    {
        return substr((string)$this->getConfigData('statement_descriptor'), 0, 22);
    }

    public function retryWithSavedCard()
    {
        return $this->getConfigData('expired_authorizations') == 1;
    }

    public function displayCardIcons(): ?string
    {
        return $this->getConfigData("card_icons");
    }

    public function getCardIcons(): string
    {
        return (string)$this->getConfigData("card_icons_specific");
    }

    public function setIsStripeAPIKeyError($isError)
    {
        $this->isStripeAPIKeyError = $isError;
    }

    public function alwaysSavePaymentMethod()
    {
        return ($this->getSavePaymentMethod() > 1 ||
            $this->quoteHelper->hasSubscriptions() ||
            ($this->isAuthorizeOnly() && $this->retryWithSavedCard()) ||
            $this->quoteHelper->isMultiShipping() ||
            $this->getPaymentAction() == "order");
    }

    public function displayMyPaymentMethodsLink()
    {
        return ($this->getSavePaymentMethod() ||
            ($this->isAuthorizeOnly() && $this->retryWithSavedCard()) ||
            $this->getPaymentAction() == "order");
    }

    public function getIsStripeAPIKeyError()
    {
        if (isset($this->isStripeAPIKeyError))
            return $this->isStripeAPIKeyError;

        return false;
    }

    public function isRedirectPaymentFlow($storeId = null)
    {
        return ($this->getConfigData('payment_flow', null, $storeId) == 1);
    }

    // Overwrite this based on business needs
    public function getMetadata($order)
    {
        $metadata = [
            "Module" => $this->module(),
            "Order #" => $order->getIncrementId()
        ];

        if ($order->getCustomerIsGuest())
            $metadata["Guest"] = "Yes";

        return $metadata;
    }

    public function getMultishippingMetadata($quote, $orders)
    {
        $orderIncrementIds = [];
        foreach ($orders as $order)
            $orderIncrementIds[] = $order->getIncrementId();

        $orders = implode(',', $orderIncrementIds);

        if (strlen($orders) > 500)
            throw new LocalizedException(__("Too many orders, please reduce shipping addresses and try again."));

        $metadata = [
            "Module" => $this->module(),
            "Cart #" => $quote->getId(),
            "Orders" => $orders,
            "Multishipping" => "Yes"
        ];

        if ($quote->getCustomerIsGuest())
            $metadata["Guest"] = "Yes";

        return $metadata;
    }

    private function module()
    {
        return Config::$moduleName . " v" . Config::$moduleVersion;
    }

    public function getStoreViewAPIKey($store, $mode)
    {
        $secretKey = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store['code']);
        if (empty($secretKey))
            return null;

        return array_merge($store->getData(), [
            'api_keys' => [
                'pk' => $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store['code']),
                'sk' => $this->decrypt($secretKey)
            ],
            'mode' => $mode,
            'mode_label' => ucfirst($mode) . " Mode",
            'default_currency' => $store->getDefaultCurrency()->getCurrencyCode()
        ]);
    }

    public function getStripeClient()
    {
        return $this->stripeClient;
    }

    public function isStripeTaxEnabled($storeId = null)
    {
        return (
            $this->configHelper->getConfigData('tax/stripe_tax/enabled', $storeId) &&
            class_exists('StripeIntegration\Tax\Model\Config')
        );
    }

    public function getStripeTaxShippingBehavior($storeId = null)
    {
        return $this->configHelper->getConfigData('tax/stripe_tax/shipping_tax_behavior', $storeId);
    }

    public function getStripeTaxPricesAndPromotionsBehavior($storeId = null)
    {
        return $this->configHelper->getConfigData('tax/stripe_tax/prices_and_promotions_tax_behavior', $storeId);
    }

    public function shippingIncludesTax($storeId = null)
    {
        if ($this->isStripeTaxEnabled($storeId))
        {
            return $this->getStripeTaxShippingBehavior($storeId) == "inclusive";
        }

        return $this->taxConfig->shippingPriceIncludesTax($storeId);
    }

    public function priceIncludesTax($storeId = null)
    {
        if ($this->isStripeTaxEnabled($storeId))
        {
            return $this->getStripeTaxPricesAndPromotionsBehavior($storeId) == "inclusive";
        }

        return $this->taxConfig->priceIncludesTax($storeId);
    }

    private function isPaymentMethodSaved($token)
    {
        if (empty($token) || !$this->tokenHelper->isPaymentMethodToken($token))
            return false;

        $paymentMethod = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($token)->getStripeObject();
        if (!empty($paymentMethod->customer))
        {
            return true;
        }

        return false;
    }

    public function getSetupFutureUsage($quote)
    {
        $isPaymentMethodSaved = $this->isPaymentMethodSaved($quote->getPayment()->getAdditionalInformation('token'));
        $hasSubscriptions = $this->isSubscriptionsEnabled() ? $this->quoteHelper->hasSubscriptions($quote) : false;
        $isSavePaymentMethodCheckboxChecked = $quote->getPayment()->getAdditionalInformation('save_payment_method');

        if ($isPaymentMethodSaved)
        {
            return null;
        }

        if ($hasSubscriptions)
            return "off_session";

        if ($this->areaCodeHelper->isAdmin())
        {
            if ($isSavePaymentMethodCheckboxChecked)
            {
                return "off_session";
            }
            else
            {
                return null;
            }
        }

        if ($this->getPaymentAction() == "order")
            return "off_session";

        if ($this->isAuthorizeOnly() && $this->retryWithSavedCard())
            return "off_session";

        if ($this->quoteHelper->isMultiShipping($quote))
            return "on_session";

        if ($this->alwaysSavePaymentMethod())
            return "on_session";

        return null;
    }

    public function enableOriginCheck()
    {
        $this->resourceConfig->saveConfig("payment/stripe_payments/webhook_origin_check", "1", "default", 0);
    }

    public function disableOriginCheck()
    {
        $this->resourceConfig->saveConfig("payment/stripe_payments/webhook_origin_check", "0", "default", 0);
    }

    public function clearCache($type)
    {
        $this->cacheTypeList->cleanType($type);
    }

    public function getMagentoMode()
    {
        return $this->appState->getMode();
    }

    public function getAllAPIKeys()
    {
        $keys = [];
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store)
        {
            foreach (['live', 'test'] as $mode)
            {
                $sk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
                $sk = (empty($sk) ? null : $this->decrypt($sk) );
                $pk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
                $pk = (empty($pk) ? null : $this->decrypt($pk) );

                if (!empty($sk) && !empty($pk))
                {
                    $keys[$sk] = $pk;
                }
            }
        }

        return $keys;
    }

    public function reCheckCVCForSavedCards()
    {
        $quote = $this->quoteHelper->getQuote();
        $this->cartInfo->setQuote($quote);

        if ($this->areaCodeHelper->isAdmin() ||
            $this->checkoutFlow->isExpressCheckout ||
            $this->checkoutFlow->isRecurringSubscriptionOrderBeingPlaced ||
            $this->cartInfo->hasSubscriptions() // See MAGENTO-1087
        )
        {
            return false;
        }

        $config = $this->getConfigData("cvc_code");

        return ($config == "new_saved_cards");
    }

    public function hasCustomerSessionFeatures($quote): bool
    {
        if ($this->alwaysSavePaymentMethod())
            return true;

        if ($this->getSavePaymentMethod() == 1 && !empty($quote->getCustomerId()))
            return true;

        return false;
    }

    public function isVerticalLayout()
    {
        if ($this->isMobile())
        {
            return true;
        }

        return $this->getConfigData('payment_element_layout');
    }

    // Override this method if you'd like to always use the vertical layout on mobile
    public function isMobile()
    {
        // $userAgent = $this->httpHeader->getHttpUserAgent();

        // if(strpos($userAgent, 'Android') !== false ||
        //     strpos($userAgent, 'iPhone') !== false ||
        //     strpos($userAgent, 'iPad') !== false ||
        //     strpos($userAgent, 'iPod') !== false)
        // {
        //     return true;
        // }

        return false;
    }

    public function getAccountModel($secretKey = null)
    {
        $publishableKey = $this->getPublishableKey();
        if (empty($secretKey))
        {
            $secretKey = $this->getSecretKey();

            if (empty($publishableKey) || empty($secretKey))
            {
                return $this->accountFactory->create();
            }
            else
            {
                $hash = hash('sha256', $secretKey);
                $accountModel = $this->accountCollectionFactory->create()->findByKeys($publishableKey, $hash);
            }
        }
        else
        {
            $hash = hash('sha256', $secretKey);
            $accountModel = $this->accountFactory->create()->load($hash, 'secret_key');
        }

        if (!$accountModel->getId() || $accountModel->needsRefresh())
        {
            try
            {
                if (!$this->getStripeClient())
                {
                    return $accountModel;
                }

                $account = $this->getStripeClient()->accounts->retrieve();
                $accountModel->fromStripeObject($account);
                $accountModel->setPublishableKey($publishableKey);
                $accountModel->setSecretKey($hash);
                $accountModel->setIsValid(true);
                $accountModel->setUpdatedAt(date('Y-m-d H:i:s'));
                $accountModel->save();
            }
            catch (\Exception $e)
            {
                $accountModel->setPublishableKey($publishableKey);
                $accountModel->setSecretKey($hash);
                $accountModel->setIsValid(false);
                $accountModel->save();
                throw $e;
            }
        }

        return $accountModel;
    }

    public function getECEMode($viewingSubscriptionProduct = false)
    {
        if ($this->isSubscriptionsEnabled())
        {
            if ($viewingSubscriptionProduct)
            {
                return "subscription";
            }
            else if ($this->quoteHelper->hasSubscriptions())
            {
                // Regular products may also exist in this cart. We still go for subscribe mode.
                return "subscription";
            }
        }

        if ($this->getPaymentAction() == "order")
        {
            return "setup";
        }

        return "payment";
    }

    public function getManualAuthenticationPaymentMethods(): array
    {
        $methods = [];
        $storeId = $this->storeHelper->getStoreId();

        if ($this->areaCodeHelper->isGraphQLRequest())
        {
            $config = $this->configHelper->getConfigData("payment/stripe_payments_advanced_configuration/manual_authentication_graphql_api", $storeId);
        }
        else
        {
            $config = $this->configHelper->getConfigData("payment/stripe_payments_advanced_configuration/manual_authentication_rest_api", $storeId);
        }

        $methods = explode(",", (string)$config);

        return $methods;
    }

    public function isTelephoneRequired($storeId = null)
    {
        if (!$storeId)
            $storeId = $this->storeHelper->getStoreId();

        $telephoneShow = $this->scopeConfig->getValue(
            'customer/address/telephone_show',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $telephoneShow == 'req';
    }

    public function isOvercaptureEnabled()
    {
        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/overcapture_enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function isMulticaptureEnabled()
    {
        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/multicapture_enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function isMissingOrderEmailsEnabled()
    {
        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/send_missing_order_emails');
    }

    public function getTaxCalculationAlgorithm($store)
    {
        if ($this->isStripeTaxEnabled($store->getId()))
        {
            return \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
        }

        return $store->getConfig('tax/calculation/algorithm');
    }

    public function isCrossBorderTradeEnabled($store)
    {
        return $store->getConfig('tax/calculation/cross_border_trade_enabled') == 1;
    }

    public function areDiscountsAppliedOnPriceIncludingTax($store)
    {
        if ($this->isStripeTaxEnabled($store->getId()))
        {
            return $this->getStripeTaxPricesAndPromotionsBehavior($store->getId()) == "inclusive";
        }

        return $store->getConfig('tax/calculation/discount_tax') == 1;
    }

    public function isExtendedAuthorizationsEnabled(): bool
    {
        if (!$this->isAuthorizeOnly())
        {
            return false;
        }

        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/extended_authorizations_enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function isMsiEnabled(): bool
    {
        // The account has to be from Mexico
        $accountModel = $this->getAccountModel();
        if ($accountModel->getCountry() != "MX") {
            return false;
        }

        // If it is an admin order or graphQL request or an express checkout order, don't show installments
        if ($this->areaCodeHelper->isAdmin() ||
            $this->areaCodeHelper->isGraphQLRequest() ||
            $this->checkoutFlow->isExpressCheckout
        ) {
            return false;
        }

        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/msi_installments_enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function isPaymentLineItemsEnabled()
    {
        return !!$this->scopeConfig->getValue('payment/stripe_payments_advanced_configuration/payment_line_items', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function getBankTransfersPendingPaymentOrderLifetime()
    {
        return $this->configHelper->getConfigData("payment/stripe_payments_bank_transfers/pending_payment_order_lifetime", $this->storeHelper->getStoreId());
    }

    public function getInvoicePendingPaymentOrderLifetime()
    {
        return $this->configHelper->getConfigData("payment/stripe_payments_invoice/pending_payment_order_lifetime", $this->storeHelper->getStoreId());
    }

    public function isInvoiceEmailEnabled()
    {
        return !!$this->configHelper->getConfigData("payment/stripe_payments_invoice/send_invoice_email", $this->storeHelper->getStoreId());
    }

    public function isPaymentMessagingElementEnabledOnCart()
    {
        return $this->configHelper->getConfigData('payment/stripe_payments_payment_method_messaging/show_in_shopping_cart', $this->storeHelper->getStoreId());
    }

    public function isPaymentMessagingElementEnabledOnMinicart()
    {
        return $this->configHelper->getConfigData('payment/stripe_payments_payment_method_messaging/show_in_minicart', $this->storeHelper->getStoreId());
    }

    public function isSubscriptionRenewalEmailEnabled()
    {
        return !!$this->configHelper->getConfigData('payment/stripe_payments_subscriptions/renewal_email_notification', $this->storeHelper->getStoreId());
    }

    public function isSubscriptionChangeEmailEnabled()
    {
        return !!$this->configHelper->getConfigData('payment/stripe_payments_subscriptions/upgrade_email_notification', $this->storeHelper->getStoreId());
    }

    public function getSubscriptionOrderEmailTemplate()
    {
        if (!$this->subscriptionFlow->isRecurringOrderCreated) {
            return null;
        }

        $this->subscriptionFlow->isRecurringOrderCreated = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/order_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionGuestOrderEmailTemplate()
    {
        if (!$this->subscriptionFlow->isRecurringOrderCreated) {
            return null;
        }

        $this->subscriptionFlow->isRecurringOrderCreated = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/guest_order_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionChangeOrderEmailTemplate()
    {
        if (!$this->subscriptionFlow->isSubscriptionChanged) {
            return null;
        }

        $this->subscriptionFlow->isSubscriptionChanged = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/change_order_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionInvoiceEmailTemplate()
    {
        if (!$this->subscriptionFlow->isRecurringOrderCreated) {
            return null;
        }

        $this->subscriptionFlow->isRecurringOrderCreated = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/invoice_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionGuestInvoiceEmailTemplate()
    {
        if (!$this->subscriptionFlow->isRecurringOrderCreated) {
            return null;
        }

        $this->subscriptionFlow->isRecurringOrderCreated = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/guest_invoice_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionCreditmemoEmailTemplate()
    {
        if (!$this->subscriptionFlow->isSubscriptionRefunded) {
            return null;
        }

        $this->subscriptionFlow->isSubscriptionRefunded = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/creditmemo_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionGuestCreditmemoEmailTemplate()
    {
        if (!$this->subscriptionFlow->isSubscriptionRefunded) {
            return null;
        }

        $this->subscriptionFlow->isSubscriptionRefunded = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/guest_creditmemo_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionShipmentEmailTemplate()
    {
        if (!$this->subscriptionFlow->isSubscriptionShipped) {
            return null;
        }

        $this->subscriptionFlow->isSubscriptionShipped = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/shipment_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function getSubscriptionGuestShipmentEmailTemplate()
    {
        if (!$this->subscriptionFlow->isSubscriptionShipped) {
            return null;
        }

        $this->subscriptionFlow->isSubscriptionShipped = false;
        return $this->configHelper->getConfigData('payment/stripe_payments_subscriptions/guest_shipment_email_template', $this->storeHelper->getStoreId()) ?: null;
    }

    public function isAdaptivePricingEnabled()
    {
        return (bool)$this->getConfigData('adaptive_pricing');
    }

    public function isAdaptivePricingApplicable($quote)
    {
        if (!$this->isAdaptivePricingEnabled()) {
            return false;
        }

        // Adaptive pricing is only supported for authorize & capture mode
        if ($this->getPaymentAction() != "authorize_capture") {
            return false;
        }

        // We need the order currency to be the same as the store base currency because of potential double-conversion
        $cartCurrency = strtolower((string)$quote->getQuoteCurrencyCode());
        $baseCurrency = strtolower((string)$this->storeHelper->getStore()->getBaseCurrencyCode());
        if ($cartCurrency !== $baseCurrency) {
            return false;
        }

        // We need the store base currency to be the same as the Stripe account settlement currency, otherwise the
        // adaptive pricing would show in the Stripe checkout
        $accountCurrency = strtolower((string)$this->getAccountModel()->getDefaultCurrency());
        if (empty($accountCurrency) || $cartCurrency !== $accountCurrency) {
            return false;
        }

        return true;
    }
}
