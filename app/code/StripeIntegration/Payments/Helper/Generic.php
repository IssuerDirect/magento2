<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Validator\Exception;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Exception\PaymentMethodInUse;
use StripeIntegration\Payments\Exception\InvalidPaymentMethod;

class Generic
{
    private $magentoCustomerId = null;
    private $urlBuilder = null;
    private $currentCustomer = null;
    private $productRepository = null;
    private $scopeConfig;
    private $request;
    private $areaCodeHelper;
    private $storeManager;
    private $invoiceService;
    private $customerSession;
    private $checkoutSession;
    private $coreRegistry;
    private $adminOrderAddressForm;
    private $customerRegistry;
    private $messageManager;
    private $cache;
    private $userContext;
    private $customerRepositoryInterface;
    private $creditmemoFactory;
    private $creditmemoService;
    private $paymentExtensionFactory;
    private $customerCollection;
    private $apiFactory;
    private $convert;
    private $storeHelper;
    private $stripeCouponFactory;
    private $checkoutHelper;
    private $ruleRepository;
    private $invoiceSender;
    private $quoteHelper;
    private $transactionSearchResultFactory;
    private $transactionRepository;
    private $creditmemoRepository;
    private $sessionManager;
    private $multishippingQuoteFactory;
    private $logger;
    private $configFactory;
    private $stripeCustomerModelFactory;
    private $orderHelper;
    private $tokenHelper;
    private $checkoutFlow;
    private $currencyHelper;
    private $errorHelper;
    private $lineItemsFactory;
    private $invoiceCapture;
    private $invoiceHelper;
    private $config = null;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Registry $coreRegistry,
        \Magento\Sales\Block\Adminhtml\Order\Create\Form\Address $adminOrderAddressForm,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Authorization\Model\UserContextInterface $userContext,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\StripeCustomer\Collection $customerCollection,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \StripeIntegration\Payments\Helper\ApiFactory $apiFactory,
        \StripeIntegration\Payments\Helper\Store $storeHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\CouponFactory $stripeCouponFactory,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository,
        \Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactionSearchResultFactory,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \StripeIntegration\Payments\Model\Multishipping\QuoteFactory $multishippingQuoteFactory,
        \StripeIntegration\Payments\Helper\Logger $logger,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Model\ConfigFactory $configFactory,
        \StripeIntegration\Payments\Helper\Error $errorHelper,
        \StripeIntegration\Payments\Model\StripeCustomerFactory $stripeCustomerModelFactory,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\Payment\LineItemsFactory $lineItemsFactory,
        \StripeIntegration\Payments\Model\Invoice\Capture $invoiceCapture,
        \StripeIntegration\Payments\Helper\Invoice $invoiceHelper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->storeManager = $storeManager;
        $this->invoiceService = $invoiceService;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->coreRegistry = $coreRegistry;
        $this->adminOrderAddressForm = $adminOrderAddressForm;
        $this->customerRegistry = $customerRegistry;
        $this->messageManager = $messageManager;
        $this->urlBuilder = $urlBuilder;
        $this->cache = $cache;
        $this->userContext = $userContext;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
        $this->customerCollection = $customerCollection;
        $this->productRepository = $productRepository;
        $this->apiFactory = $apiFactory;
        $this->storeHelper = $storeHelper;
        $this->convert = $convert;
        $this->orderHelper = $orderHelper;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->checkoutHelper = $checkoutHelper;
        $this->ruleRepository = $ruleRepository;
        $this->invoiceSender = $invoiceSender;
        $this->quoteHelper = $quoteHelper;
        $this->transactionSearchResultFactory = $transactionSearchResultFactory;
        $this->transactionRepository = $transactionRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->sessionManager = $sessionManager;
        $this->multishippingQuoteFactory = $multishippingQuoteFactory;
        $this->logger = $logger;
        $this->configFactory = $configFactory;
        $this->errorHelper = $errorHelper;
        $this->stripeCustomerModelFactory = $stripeCustomerModelFactory;
        $this->tokenHelper = $tokenHelper;
        $this->checkoutFlow = $checkoutFlow;
        $this->currencyHelper = $currencyHelper;
        $this->lineItemsFactory = $lineItemsFactory;
        $this->invoiceCapture = $invoiceCapture;
        $this->invoiceHelper = $invoiceHelper;
    }

    public function isSecure()
    {
        return $this->request->isSecure();
    }

    public function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    public function getStoreId()
    {
        return $this->storeHelper->getStoreId();
    }

    public function getCurrentStore()
    {
        return $this->storeManager->getStore();
    }

    public function loadProductBySku($sku)
    {
        try
        {
            return $this->productRepository->get($sku);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function loadCustomerById($customerId)
    {
        return $this->customerRepositoryInterface->getById($customerId);
    }

    public function isAdmin()
    {
        return $this->areaCodeHelper->isAdmin();
    }

    public function isGraphQLRequest()
    {
        return $this->areaCodeHelper->isGraphQLRequest();
    }

    public function isAPIRequest()
    {
        return $this->areaCodeHelper->isAPIRequest();
    }

    public function isCustomerLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getCustomerId()
    {
        // If we are in the back office
        if ($this->isAdmin())
        {
            // About to refund/invoice an order
            if ($order = $this->coreRegistry->registry('current_order'))
                return $order->getCustomerId();

            // About to capture an invoice
            if ($invoice = $this->coreRegistry->registry('current_invoice'))
                return $invoice->getCustomerId();

            // Creating a new order from admin
            if ($this->adminOrderAddressForm && $this->adminOrderAddressForm->getCustomerId())
                return $this->adminOrderAddressForm->getCustomerId();
        }
        // If we are on the REST API
        else if ($this->userContext->getUserType() == UserContextInterface::USER_TYPE_CUSTOMER)
        {
            return $this->userContext->getUserId();
        }
        // If we are on the checkout page
        else if ($this->customerSession->isLoggedIn())
        {
            return $this->customerSession->getCustomerId();
        }
        // A webhook has instantiated this object
        else if (!empty($this->magentoCustomerId))
        {
            return $this->magentoCustomerId;
        }

        return null;
    }

    public function getMagentoCustomer()
    {
        if ($this->customerSession->getCustomer()->getEntityId())
            return $this->customerSession->getCustomer();

        $customerId = $this->getCustomerId();
        if (!$customerId)
            return null;

        $customer = $this->customerRegistry->retrieve($customerId);

        if ($customer->getEntityId())
            return $customer;

        return null;
    }

    // Should return the email address of guest customers
    public function getCustomerEmail()
    {
        $customer = $this->getMagentoCustomer();

        if (!$customer)
            $customer = $this->getGuestCustomer();

        if (!$customer)
            return null;

        if (!$customer->getEmail())
            return null;

        return trim(strtolower($customer->getEmail()));
    }

    public function getGuestCustomer($order = null)
    {
        if ($order)
        {
            return $this->getAddressFrom($order, 'billing');
        }
        else
            return null;
    }

    public function getMultiCurrencyAmount($payment, $baseAmount)
    {
        $order = $payment->getOrder();
        $grandTotal = $order->getGrandTotal();
        $baseGrandTotal = $order->getBaseGrandTotal();

        $rate = $order->getBaseToOrderRate();
        if ($rate == 0) $rate = 1;

        // Full capture, ignore currency rate in case it changed
        if ($baseAmount == $baseGrandTotal)
        {
            return $grandTotal;
        }
        // Partial capture, consider currency rate but don't capture more than the original amount
        else if (is_numeric($rate))
        {
            if ($this->config->isOvercaptureEnabled())
            {
                return $baseAmount * $rate;
            }
            else
            {
                return min($baseAmount * $rate, $grandTotal);
            }
        }

        // Not a multicurrency capture
        return $baseAmount;
    }

    public function getAddressFrom($order, $addressType = 'shipping')
    {
        if (!$order) return null;

        $addresses = $order->getAddresses();
        if (!empty($addresses))
        {
            foreach ($addresses as $address)
            {
                if ($address["address_type"] == $addressType)
                    return $address;
            }
        }
        else if ($addressType == "shipping" && $order->getShippingAddress() && $order->getShippingAddress()->getStreet(1))
        {
            return $order->getShippingAddress();
        }
        else if ($addressType == "billing" && $order->getBillingAddress() && $order->getBillingAddress()->getStreet(1))
        {
            return $order->getBillingAddress();
        }

        return null;
    }

    public function isAuthorizationExpired($paymentObject)
    {
        // Payment intents
        if (isset($paymentObject->cancellation_reason) && $paymentObject->cancellation_reason == "expired")
            return true;

        // Charges
        if (empty($paymentObject->refunds->data[0]->reason))
            return false;

        if ($paymentObject->refunds->data[0]->reason == "expired_uncaptured_charge")
            return true;

        return false;
    }

    public function addWarning($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addWarningMessage($msg);
    }

    public function addError($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addErrorMessage( $msg );
    }

    public function addSuccess($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addSuccessMessage( $msg );
    }

    public function logError(string $msg, $trace = null)
    {
        $this->logger->logError($msg, $trace);
    }

    public function logInfo(?string $msg)
    {
        $this->logger->logInfo($msg);
    }

    public function isStripeAPIKeyError($msg)
    {
        $pos1 = stripos($msg, "Invalid API key provided");
        $pos2 = stripos($msg, "No API key provided");
        if ($pos1 !== false || $pos2 !== false)
            return true;

        return false;
    }

    public function cleanError($msg)
    {
        if ($this->isStripeAPIKeyError($msg))
            return "Invalid Stripe API key provided.";

        return $msg;
    }

    public function isMultiShipping($quote = null)
    {
        return $this->quoteHelper->isMultiShipping($quote);
    }

    public function throwError($msg, $e = null)
    {
        $this->logError($msg);

        if ($this->logger->shouldLogExceptionTrace($e))
        {
            if ($e->getMessage() != $msg)
                $this->logError($e->getMessage());

            $this->logError($e->getTraceAsString());
        }

        if ($this->isAdmin())
        {
            throw new CouldNotSaveException(__($msg));
        }
        else if ($this->isAPIRequest())
        {
            if ($this->isGraphQLRequest())
            {
                throw new \Magento\Framework\GraphQl\Exception\GraphQlInputException(__($msg));
            }
            else
            {
                $this->errorHelper->setDisplay(true);
                throw new CouldNotSaveException(__($this->cleanError($msg)), $e);
            }
        }
        else if ($this->isMultiShipping())
        {
            throw new \Magento\Framework\Exception\LocalizedException(__($msg), $e);
        }
        else
        {
            $error = $this->cleanError($msg);
            throw new GenericException($error);
        }
    }

    public function invoiceOrder($order, $transactionId = null, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE, bool $save = true)
    {
        // This will kick in with "Authorize Only" mode orders, but not with "Authorize & Capture"
        if ($order->canInvoice())
        {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($captureCase);

            if ($transactionId)
            {
                $invoice->setTransactionId($transactionId);
                $order->getPayment()->setLastTransId($transactionId);
            }

            $invoice->register();

            if ($save)
            {
                $this->invoiceHelper->saveInvoice($invoice);
                $this->orderHelper->saveOrder($order);
                $this->sendInvoiceEmail($invoice);
            }

            return $invoice;
        }
        // Invoices have already been generated with either Authorize Only or Authorize & Capture, but have not actually been captured because
        // the source is not chargeable yet. These should have a pending status.
        else
        {
            foreach($order->getInvoiceCollection() as $invoice)
            {
                $canCapture = $invoice->canCapture(); // Used in Authorize Only mode
                $isOpen = $invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_OPEN; // Used with admin area Stripe Billing asynchronous payments

                if ($canCapture || $isOpen)
                {
                    $invoice->setRequestedCaptureCase($captureCase);

                    if ($transactionId && !$invoice->getTransactionId())
                    {
                        $invoice->setTransactionId($transactionId);
                        $order->getPayment()->setLastTransId($transactionId);
                    }

                    $invoice->pay();

                    if ($save)
                    {
                        $this->invoiceHelper->saveInvoice($invoice);
                        $this->orderHelper->saveOrder($order);
                        $this->sendInvoiceEmail($invoice);
                    }

                    return $invoice;
                }
            }
        }

        return null;
    }

    public function cancelOrCloseOrder($order, \Magento\Framework\Phrase $comment, $refundInvoices = false, $refundOffline = true)
    {
        // When in Authorize & Capture, uncaptured invoices exist, so we should cancel them first
        foreach($order->getInvoiceCollection() as $invoice)
        {
            if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_CANCELED)
                continue;

            if ($invoice->canCancel())
            {
                $invoice->cancel();
                $this->invoiceHelper->saveInvoice($invoice);
            }
            else if ($refundInvoices)
            {
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $creditmemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditmemo, $refundOffline);
                $this->saveCreditmemo($creditmemo);
            }
        }

        // If invoices were canceled/refunded, a save is needed before canceling the order
        $this->orderHelper->saveOrder($order);
        $this->orderHelper->cancel($order);

        // Reload the order after cancellation and add the comment with the canceled status
        $order = $this->orderHelper->loadOrderById($order->getEntityId());
        $order->addStatusToHistory(false, $comment, false);
        $this->orderHelper->saveOrder($order);
    }

    public function getUrl($path, $additionalParams = [])
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->urlBuilder->getUrl($path, $params + $additionalParams);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function sendInvoiceEmail($invoice)
    {
        try
        {
            $this->invoiceSender->send($invoice);
            return true;
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }

        return false;
    }

    public function convertMagentoAmountToStripeAmount($amount, $currency, $allowNegatives = false)
    {
        $convertedAmount = $this->convert->magentoAmountToStripeAmount($amount, $currency);

        if (!$allowNegatives && $convertedAmount < 0)
        {
            return 0;
        }

        return $convertedAmount;
    }

    public function getMultishippingOrdersDescription($quote, $orders)
    {
        $customerName = $quote->getCustomerFirstname() . " " . $quote->getCustomerLastname();

        $orderIncrementIds = [];
        foreach ($orders as $order)
            $orderIncrementIds[] = "#" . $order->getIncrementId();

        $description = __("Multishipping orders %1 by %2", implode(", ", $orderIncrementIds), $customerName);

        return $description;
    }

    public function isStripeCheckoutMethod(?string $method)
    {
        if (empty($method))
            return false;

        return in_array($method, ["stripe_payments_checkout"]);
    }

    public function getCustomerModel()
    {
        if ($this->currentCustomer)
            return $this->currentCustomer;

        $this->currentCustomer = $this->stripeCustomerModelFactory->create();
        if ($this->currentCustomer->getStripeId())
            return $this->currentCustomer;

        $pk = $this->getPublishableKey();
        if (empty($pk))
            return $this->currentCustomer;

        $customerId = $this->getCustomerId();
        $model = null;

        if (is_numeric($customerId) && $customerId > 0)
        {
            $model = $this->customerCollection->getByCustomerId($customerId, $pk);
            if ($model && $model->getId() && $model->existsInStripe())
            {
                $model->updateSessionId();
                $this->currentCustomer = $model;
            }
        }
        else
        {
            $stripeCustomerId = $this->sessionManager->getStripeCustomerId();
            $model = null;

            if ($stripeCustomerId)
            {
                $model = $this->customerCollection->getByStripeCustomerIdAndPk($stripeCustomerId, $pk);
            }
            else
            {
                $sessionId = $this->sessionManager->getSessionId();
                $model = $this->customerCollection->getBySessionId($sessionId, $pk);
            }

            if ($model && $model->getId() && $model->existsInStripe())
                $this->currentCustomer = $model;
        }

        if (!$this->currentCustomer)
        {
            $this->currentCustomer = $this->stripeCustomerModelFactory->create();
        }

        return $this->currentCustomer;
    }

    public function getCustomerModelByStripeId($stripeId)
    {
        return $this->customerCollection->getByStripeCustomerId($stripeId);
    }

    public function getPublishableKey()
    {
        $storeId = $this->getStoreId();
        $mode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $pk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);

        if (empty($pk))
            return null;

        return trim($pk);
    }

    public function getStripeUrl($liveMode, $objectType, $id)
    {
        if ($liveMode)
            return "https://dashboard.stripe.com/$objectType/$id";
        else
            return "https://dashboard.stripe.com/test/$objectType/$id";
    }

    public function overrideInvoiceActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if ($extensionAttributes === null)
        {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }

        if (!method_exists($extensionAttributes, "setNotificationMessage"))
        {
            // Magento 2.3.5 and older did not support the notification_message extension attribute, which will cause an exception
            // https://github.com/magento/magento2/blob/2.3.6/app/code/Magento/Sales/etc/extension_attributes.xml#L17
            return;
        }

        $extensionAttributes->setNotificationMessage($msg);
    }

    public function overrideCancelActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        $payment->setMessage($msg);
        $this->overrideInvoiceActionComment($payment, $msg);
    }

    public function capture($token, $payment, $amount, $useSavedCard = false)
    {
        $token = $this->tokenHelper->cleanToken($token);
        $order = $payment->getOrder();

        if ($token == "cannot_capture_subscriptions")
        {
            $msg = __("Subscription items cannot be captured online. Capturing offline instead.");
            $this->addWarning($msg);
            $this->overrideInvoiceActionComment($payment, $msg);
            return;
        }

        try
        {
            $paymentObject = $ch = null;
            $finalAmount = $amountToCapture = 0;

            if ($this->tokenHelper->isPaymentIntentToken($token))
            {
                /** @var \Stripe\PaymentIntent $paymentIntent */
                $paymentIntent = $this->getConfig()->getStripeClient()->paymentIntents->retrieve($token, ['expand' => ['latest_charge']]);

                if (empty($paymentIntent->latest_charge) || $paymentIntent->status == "requires_action")
                    $this->throwError(__("The payment for this order has not been authorized yet."));

                $charge = $paymentIntent->latest_charge;
                $paymentObject = $paymentIntent;
                $amountToCapture = "amount_to_capture";
            }
            else if ($this->tokenHelper->isChargeToken($token))
            {
                $charge = $this->getConfig()->getStripeClient()->charges->retrieve($token);
                $paymentObject = $charge;
                $amountToCapture = "amount";
            }
            else
            {
                return $this->throwError(__("We do not know how to capture payments with a token of this format."));
            }

            $currency = $charge->currency;

            if ($currency == strtolower($order->getOrderCurrencyCode()))
                $finalAmount = $this->getMultiCurrencyAmount($payment, $amount);
            else if ($currency == strtolower($order->getBaseCurrencyCode()))
                $finalAmount = $amount;
            else
                $this->throwError(__("Cannot capture payment because it was created using a different currency (%1).", $charge->currency));

            $stripeAmount = $this->convert->magentoAmountToStripeAmount($finalAmount, $currency);

            if ($this->isAuthorizationExpired($paymentObject))
            {
                if ($useSavedCard)
                {
                    return $this->apiFactory->create()->reCreateCharge($payment, $amount, $charge);
                }
                else
                    return $this->throwError("The payment authorization with the customer's bank has expired. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
            }
            else if ($charge->refunded)
            {
                $this->throwError("The amount for this invoice has been refunded in Stripe.");
            }
            else if ($charge->captured)
            {
                $capturedAmount = $charge->amount - $charge->amount_refunded;
                $humanReadableAmount = $this->currencyHelper->formatStripePrice($stripeAmount - $capturedAmount, $charge->currency);

                if ($order->getInvoiceCollection()->getSize() > 0)
                {
                    foreach ($order->getInvoiceCollection() as $invoice)
                    {
                        if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID)
                        {
                            if ($invoice->getGrandTotal() < $order->getGrandTotal()) // Is this a partial invoice?
                            {
                                if ($useSavedCard)
                                {
                                    $this->apiFactory->create()->reCreateCharge($payment, $amount, $charge);
                                    return;
                                }
                                else
                                {
                                    return $this->throwError("The payment has already been partially captured, and the remaining amount has been released. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
                                }
                            }
                            else
                            {
                                // In theory we should never get in here because Magento cannot Invoice orders which have already been fully invoiced..
                                $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);
                                $this->addWarning($msg);
                                $this->orderHelper->addOrderComment($msg, $order);
                                return;
                            }
                        }
                    }
                }

                if ($this->orderHelper->hasTrialSubscriptionsIn($order->getAllItems()))
                {
                    $msg = __("%1 could not be captured online because this cart includes subscriptions which are trialing. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else if (($stripeAmount - $capturedAmount) == 0)
                {
                    // Case with a regular item and a subscription with PaymentElement, before the webhook arrives.
                    $humanReadableAmount = $this->currencyHelper->formatStripePrice($stripeAmount, $charge->currency);
                    $msg = __("%1 was already captured via Stripe. The invoice was in Pending status, likely because a webhook could not be delivered to your website. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else
                    $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);

                $this->addWarning($msg);
                $this->overrideInvoiceActionComment($payment, $msg);
            }
            else // status == pending
            {
                if ($this->getConfig()->isOvercaptureEnabled() &&
                    !empty($charge->payment_method_details->card->overcapture->maximum_amount_capturable))
                {
                    $availableAmount = $charge->payment_method_details->card->overcapture->maximum_amount_capturable;
                }
                else
                {
                    $availableAmount = $charge->amount;
                }

                if ($availableAmount < $stripeAmount)
                {
                    $available = $this->currencyHelper->formatStripePrice($availableAmount, $charge->currency);
                    $requested = $this->currencyHelper->formatStripePrice($stripeAmount, $charge->currency);

                    throw new LocalizedException(__("The maximum available amount to capture is %1, but a capture of %2 was requested.", $available, $requested));
                }

                $key = "admin_captured_" . $paymentObject->id;
                try
                {
                    $this->cache->save($value = "1", $key, ["stripe_payments"], $lifetime = 60 * 60);

                    $captureParams = [$amountToCapture => $stripeAmount];

                    if ($this->config->isMulticaptureEnabled())
                    {
                        $captureParams["final_capture"] = ($paymentObject->amount_capturable == $stripeAmount);
                    }

                    // Add line items for partial invoice captures
                    $invoice = $this->invoiceCapture->getInvoice();
                    if ($invoice)
                    {
                        $lineItems = $this->lineItemsFactory->create();
                        $lineItems->fromInvoice($invoice);
                        $lineItemsData = $lineItems->getPaymentIntentFormat();

                        if (!empty($lineItemsData['amount_details']))
                        {
                            $captureParams['amount_details'] = $lineItemsData['amount_details'];
                        }

                        if (!empty($lineItemsData['payment_details']))
                        {
                            $captureParams['payment_details'] = $lineItemsData['payment_details'];
                        }

                        // Clear the invoice after we're done using it
                        $this->invoiceCapture->clearInvoice();
                    }

                    $paymentObject->capture($captureParams);
                }
                catch (\Exception $e)
                {
                    $this->cache->remove($key);
                    throw $e;
                }
            }
        }
        catch (\Exception $e)
        {
            $message = "The provided PaymentMethod cannot be attached";
            if (substr($e->getMessage(), 0, strlen($message)) === $message)
            {
                return $this->throwError("The authorization has expired and the payment method used for this order was not saved. To collect a new payment method, click the Reorder button and select the Stripe Billing payment method.");
            }
            $this->throwError($e->getMessage(), $e);
        }
    }

    public function deduplicatePaymentMethod($customerId, $paymentMethodId, $paymentMethodType, $fingerprint, $stripeClient)
    {
        if ($paymentMethodType != "card" || empty($fingerprint) || empty($customerId) || empty($paymentMethodId))
            return;

        try
        {

            switch ($paymentMethodType)
            {
                case "card":

                    $subscriptions = [];
                    $data = $stripeClient->subscriptions->all(['limit' => 100, 'customer' => $customerId]);
                    foreach ($data->autoPagingIterator() as $subscription)
                        $subscriptions[] = $subscription;

                    $collection = $stripeClient->paymentMethods->all([
                      'customer' => $customerId,
                      'type' => $paymentMethodType
                    ]);

                    try
                    {
                        $stripeCustomer = $this->stripeCustomerModelFactory->create()->fromStripeCustomerId($customerId);
                        $invoiceSettingsDefaultPaymentMethod = $stripeCustomer->getInvoiceSettingsDefaultPaymentMethod();
                    }
                    catch (\Exception $e)
                    {
                        $this->logError($e->getMessage(), $e->getTraceAsString());
                        return;
                    }

                    // Find and detach OLD payment methods from the customer that have the same fingerprint as the new one,
                    // except for the current default payment method, and except for payment methods used by active subscriptions
                    foreach ($collection->data as $paymentMethod)
                    {
                        if ($paymentMethod['id'] == $paymentMethodId) {
                            // Don't delete the payment method that was just added
                            continue;
                        }

                        if ($paymentMethod[$paymentMethodType]['fingerprint'] != $fingerprint || $invoiceSettingsDefaultPaymentMethod == $paymentMethod['id']) {
                            // Don't delete payment methods that have a different fingerprint, or the current default payment method
                            continue;
                        }

                        try
                        {
                            $stripeCustomer->detachPaymentMethod($paymentMethod['id']);
                        }
                        catch (PaymentMethodInUse|InvalidPaymentMethod $e)
                        {
                            continue;
                        }
                        catch (\Exception $e)
                        {
                            $this->logError($e->getMessage());
                            $this->logError($e->getTraceAsString());
                        }
                    }

                    break;

                default:

                    break;
            }
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage());
            $this->logError($e->getTraceAsString());
        }
    }

    public function loadRuleByRuleId($ruleId)
    {
        return $this->ruleRepository->getById($ruleId);
    }

    public function loadStripeCouponByRuleId($ruleId)
    {
        return $this->stripeCouponFactory->create()->load($ruleId, 'rule_id');
    }

    public function sendPaymentFailedEmail($quote, $msg)
    {
        try
        {
            if (!$quote)
                $quote = $this->quoteHelper->getQuote();

            $this->checkoutHelper->sendPaymentFailedEmail($quote, $msg);
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function resetPaymentData($payment)
    {
        if ($payment->getPaymentId())
            return;

        // Reset a previously initialized 3D Secure session
        $payment->setAdditionalInformation('stripejs_token', null)
            ->setAdditionalInformation('token', null)
            ->setAdditionalInformation("save_payment_method", null)
            ->setAdditionalInformation("confirmation_token", null)
            ->setAdditionalInformation("is_recurring_subscription", null)
            ->setAdditionalInformation("is_migrated_subscription", null)
            ->setAdditionalInformation("remove_initial_fee", null)
            ->setAdditionalInformation("customer_stripe_id", null)
            ->setAdditionalInformation("payment_location", null)
            ->setAdditionalInformation("client_secret", null)
            ->setAdditionalInformation("is_subscription_update", null);
    }

    public function assignPaymentData($payment, $data)
    {
        $config = $this->getConfig();

        $this->resetPaymentData($payment);

        if ($this->isMultiShipping())
        {
            $payment->setAdditionalInformation("payment_location", "Multishipping checkout");
        }
        else if ($this->isAdmin())
        {
            $payment->setAdditionalInformation("payment_location", "Admin area");
        }
        else if (!empty($data['is_migrated_subscription']))
        {
            $payment->setAdditionalInformation("payment_location", "CLI migrated subscription order");
        }
        else if ($this->checkoutFlow->isRecurringSubscriptionOrderBeingPlaced)
        {
            $payment->setAdditionalInformation("is_recurring_subscription", true);
            $payment->setAdditionalInformation("payment_location", "Recurring subscription order");
        }
        else if ($this->checkoutFlow->isExpressCheckout)
        {
            if (!empty($data['payment_location']))
            {
                $location = "Express Checkout via " . $data['payment_location'] . " page";
                $payment->setAdditionalInformation("payment_location", $location);
            }
            else
            {
                $payment->setAdditionalInformation("payment_location", "Express Checkout");
            }
        }

        if (!empty($data['payment_method']) && $this->tokenHelper->isExternalPaymentMethodToken($data['payment_method']))
        {
            $payment->setAdditionalInformation('token', $data['payment_method']);
            $payment->setAdditionalInformation('confirmation_token', null);
        }
        else if (!empty($data['payment_method']))
        {
            $payment->setAdditionalInformation('token', $data['payment_method']);
            $payment->setAdditionalInformation('confirmation_token', null);

            if (isset($data['save_payment_method']) && $data['save_payment_method'])
                $payment->setAdditionalInformation('save_payment_method', true);
        }
        else if (!empty($data['confirmation_token']))
        {
            // Used by the express checkout element
            $payment->setAdditionalInformation('confirmation_token', $data['confirmation_token']);
            $payment->setAdditionalInformation('token', null);
        }

        if ($this->isMultiShipping())
        {
            $quoteId = $payment->getQuoteId();
            $multishippingQuoteModel = $this->multishippingQuoteFactory->create();
            $multishippingQuoteModel->load($quoteId, 'quote_id');
            $multishippingQuoteModel->setQuoteId($quoteId);
            $multishippingQuoteModel->setPaymentMethodId($payment->getAdditionalInformation('token'));
            $multishippingQuoteModel->setConfirmationToken($payment->getAdditionalInformation('confirmation_token'));
            $multishippingQuoteModel->save();
        }

        if (!empty($data['is_migrated_subscription']))
            $payment->setAdditionalInformation('is_migrated_subscription', true);


        // Set additional information on payment only if one of the options from the modal was selected. We determine
        // by checking if the data coming back from JS is a string.
        if ($config->isMsiEnabled() &&
            !empty($data['selected_installment_plan']) &&
            is_string($data['selected_installment_plan'])
        ) {
            $payment->setAdditionalInformation('selected_installment_plan', $data['selected_installment_plan']);
        }
    }

    /**
     * Transaction interface types
     * const TYPE_PAYMENT = 'payment';
     * const TYPE_ORDER = 'order';
     * const TYPE_AUTH = 'authorization';
     * const TYPE_CAPTURE = 'capture';
     * const TYPE_VOID = 'void';
     * const TYPE_REFUND = 'refund';
     **/
    public function addTransaction($order, $transactionId, $transactionType = "capture", $parentTransactionId = null)
    {
        try
        {
            $payment = $order->getPayment();

            if ($parentTransactionId)
            {
                $payment->setTransactionId($transactionId . "-$transactionType");
                $payment->setParentTransactionId($parentTransactionId);
            }
            else
            {
                $payment->setTransactionId($transactionId);
                $payment->setParentTransactionId(null);
            }

            $transaction = $payment->addTransaction($transactionType, null, true);
            return  $transaction;
        }
        catch (Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function getOrderTransactions($order)
    {
        $transactions = $this->transactionSearchResultFactory->create()->addOrderIdFilter($order->getId());
        return $transactions->getItems();
    }

    public function clearCache()
    {
        $this->quoteHelper->clearCache();
        $this->orderHelper->clearCache();
        return $this;
    }

    public function saveTransaction($transaction)
    {
        return $this->transactionRepository->save($transaction);
    }

    public function saveCreditmemo($creditmemo)
    {
        return $this->creditmemoRepository->save($creditmemo);
    }

    public function setProcessingState($order, $comment = null, $isCustomerNotified = false)
    {
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function setOrderState($order, $state, $comment = null, $isCustomerNotified = false)
    {
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function getStripeApiTimeDifference()
    {
        $timeDifference = $this->cache->load("stripe_api_time_difference");
        if (!is_numeric($timeDifference))
        {
            $localTime = time();
            $product = $this->getConfig()->getStripeClient()->products->create([
               'name' => 'Time Query',
               'type' => 'service'
            ]);
            $timeDifference = $product->created - ($localTime + 1); // The 1 added second accounts for the delay in creating the product
            $this->cache->save($timeDifference, $key = "stripe_api_time_difference", $tags = ["stripe_payments"], $lifetime = 24 * 60 * 60);
            $product->delete();
        }
        return $timeDifference;
    }

    public function isTransactionId($transactionId)
    {
        if (empty($transactionId))
            return false;

        $isPaymentIntent = (strpos($transactionId, "pi_") === 0);
        $isCharge = (strpos($transactionId, "ch_") === 0);
        return ($isPaymentIntent || $isCharge);
    }

    public function getOrdersByTransactionId($transactionId)
    {
        $orders = [];

        if (!$this->isTransactionId($transactionId))
            return $orders;

        $transactions = $this->transactionSearchResultFactory->create()->addFieldToFilter('txn_id', $transactionId);

        foreach ($transactions as $transaction)
        {
            if (!$transaction->getOrderId())
                continue;

            $orderId = $transaction->getOrderId();
            if (isset($orders[$orderId]))
                continue;

            try
            {
                $orders[$orderId] = $this->orderHelper->loadOrderById($orderId);
            }
            catch (\Magento\Framework\Exception\NoSuchEntityException $e)
            {
                continue;
            }
        }

        return $orders;
    }

    public function getCache()
    {
        return $this->cache;
    }

    public function removeTransactions($order)
    {
        $this->orderHelper->removeTransactions($order);
    }

    protected function getConfig()
    {
        if (!$this->config) {
            $this->config = $this->configFactory->create();
        }

        return $this->config;
    }
}
