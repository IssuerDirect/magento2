<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\CacheInvalidationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;
use StripeIntegration\Payments\Exception\RequiresMicrodepositVerification;
use StripeIntegration\Payments\Model\Cart\Info;

class Subscriptions
{
    public $couponCodes = [];
    public $coupons = [];
    public $subscriptions = [];
    public $invoices = [];
    public $paymentIntents = [];
    public $trialingSubscriptionsAmounts = null;
    private $futureSubscriptionsDetails = null;
    public $shippingTaxPercent = null;

    private $localCache = [];
    private $addressHelper;
    private $subscriptionProductFactory;
    private $paymentIntentModelFactory;
    private $stripeSubscriptionFactory;
    private $stripeProductFactory;
    private $stripePriceFactory;
    private $stripeCouponFactory;
    private $customer;
    private $compare;
    private $paymentIntentHelper;
    private $taxHelper;
    private $config;
    private $paymentsHelper;
    private $subscriptionOptionsFactory;
    private $startDateFactory;
    private $subscriptionScheduleFactory;
    private $quoteHelper;
    private $checkoutSessionHelper;
    private $orderHelper;
    private $checkoutFlow;
    private $convert;
    private $paymentMethodTypesHelper;
    private $subscriptionCartFactory;
    private $productHelper;
    private $currencyHelper;
    private $subscriptionResourceModel;
    private $subscriptionCollection;
    private $cartInfo;
    private $invoicePaymentsHelper;
    private $stripeSetupIntentModelFactory;
    private $setupIntentResourceModel;
    private \StripeIntegration\Payments\Model\ResourceModel\SetupIntent\Collection $setupIntentCollection;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\PaymentMethodTypes $paymentMethodTypesHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentModelFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \StripeIntegration\Payments\Model\Stripe\ProductFactory $stripeProductFactory,
        \StripeIntegration\Payments\Model\Stripe\PriceFactory $stripePriceFactory,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $stripeCouponFactory,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper,
        \StripeIntegration\Payments\Model\SubscriptionOptionsFactory $subscriptionOptionsFactory,
        \StripeIntegration\Payments\Model\Subscription\StartDateFactory $startDateFactory,
        \StripeIntegration\Payments\Model\Subscription\ScheduleFactory $subscriptionScheduleFactory,
        \StripeIntegration\Payments\Model\Subscription\CartFactory $subscriptionCartFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription $subscriptionResourceModel,
        \StripeIntegration\Payments\Model\ResourceModel\SetupIntent $setupIntentResourceModel,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription\Collection $subscriptionCollection,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Model\Stripe\SetupIntentFactory $stripeSetupIntentModelFactory,
        \StripeIntegration\Payments\Model\ResourceModel\SetupIntent\Collection $setupIntentCollection,
        Info $cartInfo
    ) {
        $this->paymentsHelper = $paymentsHelper;
        $this->compare = $compare;
        $this->addressHelper = $addressHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->convert = $convert;
        $this->config = $config;
        $this->paymentMethodTypesHelper = $paymentMethodTypesHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->paymentIntentModelFactory = $paymentIntentModelFactory;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->stripeProductFactory = $stripeProductFactory;
        $this->stripePriceFactory = $stripePriceFactory;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->checkoutFlow = $checkoutFlow;
        $this->customer = $paymentsHelper->getCustomerModel();
        $this->taxHelper = $taxHelper;
        $this->subscriptionOptionsFactory = $subscriptionOptionsFactory;
        $this->startDateFactory = $startDateFactory;
        $this->subscriptionScheduleFactory = $subscriptionScheduleFactory;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->productHelper = $productHelper;
        $this->currencyHelper = $currencyHelper;
        $this->subscriptionCartFactory = $subscriptionCartFactory;
        $this->subscriptionResourceModel = $subscriptionResourceModel;
        $this->subscriptionCollection = $subscriptionCollection;
        $this->cartInfo = $cartInfo;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->stripeSetupIntentModelFactory = $stripeSetupIntentModelFactory;
        $this->setupIntentCollection = $setupIntentCollection;
        $this->setupIntentResourceModel = $setupIntentResourceModel;
    }

    public function getSubscriptionExpandParams()
    {
        return ['latest_invoice.payment_intent', 'pending_setup_intent'];
    }

    public function getSubscriptionParamsFromOrder($order, $paymentIntentParams)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return null;

        $subscription = $this->getSubscriptionFromOrder($order);
        $profile = $subscription['profile'];
        $subscriptionItems = $this->getSubscriptionItemsFromSubscriptionDetails($subscription);

        if (empty($subscriptionItems))
            return null;

        $stripeCustomer = $this->customer->createStripeCustomerIfNotExists();
        $this->customer->save();

        if (!$stripeCustomer)
            throw new GenericException("Could not create customer in Stripe.");

        $metadata = $subscriptionItems[0]['metadata']; // There is only one item for the entire order

        $params = [
            'description' => $this->orderHelper->getOrderDescription($order),
            'customer' => $stripeCustomer->id,
            'items' => $subscriptionItems,
            'expand' => $this->getSubscriptionExpandParams(),
            'metadata' => $metadata,
            'billing_mode' => ['type' => 'classic'],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ]
        ];

        $paymentMethodTypes = $this->paymentMethodTypesHelper->getPaymentMethodTypes();
        if ($paymentMethodTypes)
        {
            $params['payment_settings']['payment_method_types'] = $paymentMethodTypes;
        }

        if ($paymentIntentParams['amount'] > 0)
        {
            $stripeDiscountAdjustment = $this->getStripeDiscountAdjustment($subscription);
            $normalPrice = $this->createPriceForOneTimePayment($paymentIntentParams['amount'] + $stripeDiscountAdjustment, $paymentIntentParams['currency']);
            $params['add_invoice_items'] = [[
                "price" => $normalPrice->id,
                "quantity" => 1
            ]];
        }

        $hasOneTimePayment = !empty($params['add_invoice_items']);
        $startDateModel = $this->startDateFactory->create()->fromProfile($profile);

        if (empty($paymentIntentParams['payment_method']))
        {
            try
            {
                $resolvedPaymentMethodId = $this->resolveConfirmationTokenToPaymentMethod($order);
                if ($resolvedPaymentMethodId)
                {
                    $paymentIntentParams['payment_method'] = $resolvedPaymentMethodId;
                }
            }
            catch (RequiresMicrodepositVerification $e)
            {
                $this->checkoutFlow->isPendingMicrodepositsVerification = true;

                // Subscription will be created via setup_intent.succeeded webhook.
                return null;
            }
        }

        if (!empty($paymentIntentParams['payment_method']))
        {
            $params['default_payment_method'] = $paymentIntentParams['payment_method'];
        }
        else if ($this->checkoutSessionHelper->isSubscriptionReactivate())
        {
            $subscriptionReactivateDetails = $this->checkoutSessionHelper->getSubscriptionReactivateDetails();
            $paymentMethodId = $this->orderHelper->getPaymentMethodId($order);
            if (isset($subscriptionReactivateDetails['subscription_data']['default_payment_method']))
            {
                $params['default_payment_method'] = $subscriptionReactivateDetails['subscription_data']['default_payment_method'];
            }
            else if ($paymentMethodId)
            {
                $params['default_payment_method'] = $paymentMethodId;
            }
        }

        if (!empty($profile['expiring_coupon']))
        {
            $coupon = $this->stripeCouponFactory->create()->fromSubscriptionProfile($profile);
            if ($coupon->getId())
                $params['discounts'] = [
                    [ 'coupon' => $coupon->getId() ]
                ];
        }

        $startDateModel = $this->startDateFactory->create()->fromProfile($profile);
        $hasOneTimePayment = !empty($params['add_invoice_items']);
        if ($startDateModel->isCompatibleWithTrials($hasOneTimePayment))
        {
            if ($profile['trial_end'])
            {
                $params['trial_end'] = $profile['trial_end'];
            }
            else if ($profile['trial_days'])
            {
                $params['trial_period_days'] = $profile['trial_days'];
            }
        }

        return $params;
    }

    public function filterToUpdateableParams($params)
    {
        $updateParams = [];

        if (empty($params))
            return $updateParams;

        $updateable = ['metadata', 'trial_end', 'description', 'default_payment_method'];

        foreach ($params as $key => $value)
        {
            if (in_array($key, $updateable))
                $updateParams[$key] = $value;
        }

        return $updateParams;
    }

    public function invalidateSubscription($subscription, $params)
    {
        $subscriptionItems = [];

        foreach ($params["items"] as $item)
        {
            $subscriptionItems[] = [
                "metadata" => [
                    "Type" => $item["metadata"]["Type"],
                    "SubscriptionProductIDs" => $item["metadata"]["SubscriptionProductIDs"]
                ],
                "price" => [
                    "id" => $item["price"]
                ],
                "quantity" => $item["quantity"]
            ];
        }

        $expectedValues = [
            "customer" => $params["customer"],
            "items" => [
                "data" => $subscriptionItems
            ]
        ];

        if (!empty($params['add_invoice_items']))
        {
            $oneTimeAmount = "unset";
            foreach ($params['add_invoice_items'] as $item)
            {
                $oneTimeAmount = [
                    "pricing" => [
                        "price_details" => [
                            "price" => $item["price"]
                        ]
                    ],
                    "quantity" => $item["quantity"]
                ];
            }

            if (empty($subscription->latest_invoice->lines->data))
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");

            $hasRegularItems = false;
            foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
            {
                if ($this->isSubscriptionLineItem($invoiceLineItem))
                    continue; // This is a subscription item

                $hasRegularItems = true;

                if ($this->compare->isDifferent($invoiceLineItem, $oneTimeAmount))
                {
                    throw new CacheInvalidationException("Non-updateable subscription details have changed: One time payment amount has changed.");
                }
            }

            if (!$hasRegularItems && $oneTimeAmount !== "unset")
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");
        }
        else
        {
            if (!empty($subscription->latest_invoice->lines->data))
            {
                foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
                {
                    if (!$this->isSubscriptionLineItem($invoiceLineItem))
                        throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were removed from the cart.");
                }
            }
        }

        if (!empty($subscription->latest_invoice))
        {
            if (!empty($params['discounts'][0]['coupon']))
            {
                $expectedValues['latest_invoice']['discounts'][0]['source']['coupon'] = $params['discounts'][0]['coupon'];
            }
            else
            {
                $expectedValues['latest_invoice']['discounts'] = "empty";
            }
        }

        if ($this->compare->isDifferent($subscription, $expectedValues))
            throw new CacheInvalidationException("Non-updateable subscription details have changed: " . $this->compare->lastReason);
    }

    private function isSubscriptionLineItem($invoiceLineItem)
    {
        return isset($invoiceLineItem->parent->type) && $invoiceLineItem->parent->type == "subscription_item_details";
    }

    // WARNING
    // This is used by the CLI subscription creation command.
    // It does not try to collect initial fees or payments for non-subscription items on the order.
    // It also ignores trial periods on the subscription profile and only sets a trial if passed as a parameter.
    public function createSubscriptionFromOrder(
        $order,
        \StripeIntegration\Payments\Model\StripeCustomer $stripeCustomerModel,
        ?string $paymentMethodId = null,
        ?int $trialEnd = null
    )
    {
        if (!$this->config->isSubscriptionsEnabled())
        {
            throw new GenericException("Subscriptions are disabled");
        }

        $subscription = $this->getSubscriptionFromOrder($order);

        $stripeProductModel = $this->stripeProductFactory->create()->fromOrderItem($subscription['order_item']);
        $recurringPrice = $this->createSubscriptionPriceForSubscription($subscription['profile'], $stripeProductModel);
        $metadata = $this->collectMetadataForSubscription($subscription['profile']);

        $subscriptionItems[] = [
            "metadata" => $metadata,
            "price" => $recurringPrice->id,
            "quantity" => 1
        ];

        $params = [
            'description' => $this->orderHelper->getOrderDescription($order),
            'customer' => $stripeCustomerModel->getStripeId(),
            'items' => $subscriptionItems,
            'expand' => $this->getSubscriptionExpandParams(),
            'metadata' => $metadata,
            'billing_mode' => ['type' => 'classic'],
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ]
        ];

        if (!empty($paymentMethodId))
        {
            $params['default_payment_method'] = $paymentMethodId;
            $stripeCustomerModel->attachPaymentMethod($paymentMethodId);
        }
        else
        {
            $params['payment_behavior'] = "allow_incomplete";
        }

        if (!empty($subscription['profile']['expiring_coupon']))
        {
            $coupon = $this->stripeCouponFactory->create()->fromSubscriptionProfile($subscription['profile']);
            if ($coupon->getId() && $coupon->getStripeObject()->duration == "forever")
            {
                $params['discounts'] = [
                    [ 'coupon' => $coupon->getId() ]
                ];
            }
        }

        if (!empty($trialEnd))
        {
            $params["trial_end"] = $trialEnd;
        }

        $subscription = $this->config->getStripeClient()->subscriptions->create($params);
        $this->updateSubscriptionEntry($subscription, $order);
        return $subscription;
    }

    private function getBalanceUpdateDataFromSubscription($subscriptionProfile)
    {
        $data = [
            'amount' => $subscriptionProfile['customer_balance_adjustment'],
            'currency' => $subscriptionProfile['currency'],
            'description' => $subscriptionProfile['name'] . ' adjustment.',
            'metadata' => ['order_increment_id' => $subscriptionProfile['order_increment_id']]
        ];

        return $data;
    }

    public function createSubscription($subscriptionCreationParams, $order, $profile)
    {
        $hasOneTimePayment = !empty($subscriptionCreationParams['add_invoice_items']);
        $startDateModel = $this->startDateFactory->create()->fromProfile($profile);
        $startDateParams = $startDateModel->getParams($hasOneTimePayment);

        $this->customer->updateBalance($this->getBalanceUpdateDataFromSubscription($profile));

        if ($this->checkoutSessionHelper->isSubscriptionReactivate())
        {
            // Use the provided params as-is, don't let start date configurations interfere.
            $subscription = $this->config->getStripeClient()->subscriptions->create($subscriptionCreationParams);
        }
        else if ($startDateModel->hasPhases())
        {
            $schedule = $this->subscriptionScheduleFactory->create([
                'subscriptionCreateParams' => $subscriptionCreationParams,
                'startDate' => $startDateModel,
            ]);

            $subscription = $schedule->create()->finalize()->getSubscription();

            $order->getPayment()->setAdditionalInformation('subscription_schedule_id', $schedule->getId());
        }
        else if (!empty($startDateParams))
        {
            $subscriptionCreationParams = array_merge_recursive($subscriptionCreationParams, $startDateParams);
            $subscription = $this->config->getStripeClient()->subscriptions->create($subscriptionCreationParams);
        }
        else
        {
            $subscription = $this->config->getStripeClient()->subscriptions->create($subscriptionCreationParams);
        }

        $this->updateSubscriptionEntry($subscription, $order);
        return $subscription;
    }

    public function updateSubscriptionFromOrder($order, $subscriptionId, $paymentIntentParams)
    {
        $subscription = $this->getSubscriptionFromOrder($order);

        if (empty($subscription))
            return null;

        $profile = $subscription['profile'];
        $params = $this->getSubscriptionParamsFromOrder($order, $paymentIntentParams);

        if (empty($params))
            return null;

        if (!empty($params['default_payment_method']))
        {
            try
            {
                $this->customer->attachPaymentMethod($params['default_payment_method']);
            }
            catch (\Exception $e)
            {
                $message = $e->getMessage();
                if (strpos($message, 'must be verified') !== false)
                {
                    $this->paymentsHelper->throwError(__("The selected payment requires a micro-deposit verification. Please select a different payment method."));
                }
                else
                {
                    throw $e;
                }
            }
        }

        if (!$subscriptionId)
        {
            $checkoutSession = $this->paymentsHelper->getCheckoutSession();
            $subscriptionReactivateDetails = $checkoutSession->getSubscriptionReactivateDetails();
            if ($subscriptionReactivateDetails) {
                if (isset($subscriptionReactivateDetails['update_subscription_id'])
                    && $subscriptionReactivateDetails['update_subscription_id']) {
                    $subscriptionModel = $this->loadSubscriptionModelBySubscriptionId($subscriptionReactivateDetails['update_subscription_id']);
                    if ($subscriptionModel)
                    {
                        $subscriptionModel->setStatus('reactivated');
                        $subscriptionModel->save();
                    }
                }

                if (isset($subscriptionReactivateDetails['subscription_data']) && $subscriptionReactivateDetails['subscription_data'])
                {
                    if (!empty($params['default_payment_method']))
                    {
                        $subscriptionReactivateDetails['subscription_data']['default_payment_method'] = $params['default_payment_method'];
                    }
                    $subscriptionReactivateDetails['subscription_data']['metadata'] = $params['metadata'];
                    $params = $subscriptionReactivateDetails['subscription_data'];
                }
            }

            return $this->createSubscription($params, $order, $subscription['profile']);
        }

        $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, [
            'expand' => $this->getSubscriptionExpandParams()
        ]);

        try
        {
            $this->invalidateSubscription($subscription, $params);
        }
        catch (CacheInvalidationException $e)
        {
            $this->config->getStripeClient()->subscriptions->cancel($subscription->id, []);
            return $this->createSubscription($params, $order, $profile);
        }

        $updateParams = $this->filterToUpdateableParams($params);

        if (empty($updateParams))
        {
            $this->updateSubscriptionEntry($subscription, $order);
            return $subscription;
        }

        if ($this->compare->isDifferent($subscription, $updateParams))
        {
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);
        }

        if (!empty($params['expand']))
        {
            $updateParams['expand'] = $params['expand'];
        }

        $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromSubscription($subscription);
        if (!empty($paymentIntent->id))
        {
            $params = [];
            $params["description"] = $this->orderHelper->getOrderDescription($order);
            $params["metadata"] = $this->config->getMetadata($order);

            $shipping = $this->addressHelper->getShippingAddressFromOrder($order);
            if ($shipping)
                $params['shipping'] = $shipping;

            if (!empty($updateParams['default_payment_method']))
                $params['payment_method'] = $updateParams['default_payment_method'];

            $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $paymentIntent);
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);
        }

        $this->updateSubscriptionEntry($subscription, $order);

        return $subscription;
    }

    // Used by the CLI migration tool
    public function updateSubscriptionPriceFromOrder($subscription, $order)
    {
        $upcomingInvoice = $this->config->getStripeClient()->invoices->createPreview([
            'subscription' => $subscription->id
        ]);

        if (!empty($upcomingInvoice->discounts))
        {
            throw new GenericException("This subscription cannot be changed because it's upcoming invoice includes a discount coupon.");
        }

        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $paymentIntentParams = $paymentIntentModel->getParamsFrom($order);
        $params = $this->getSubscriptionParamsFromOrder($order, $paymentIntentParams);

        if (empty($params['items']) || empty($params['metadata']))
            throw new GenericException("Could not update subscription price.");

        $deletedItems = [];
        foreach ($subscription->items->data as $lineItem)
        {
            $deletedItems[] = [
                "id" => $lineItem['id'],
                "deleted" => true
            ];
        }

        $items = array_merge($deletedItems, $params['items']);
        $updateParams = [
            'items' => $items,
            'metadata' => $params['metadata'],
            'proration_behavior' => "none"
        ];

        return $this->config->getStripeClient()->subscriptions->update($subscription->id, $updateParams);
    }

    public function getSubscriptionItemsFromSubscriptionDetails($subscription)
    {
        if (empty($subscription))
            return null;

        if (!empty($subscription['quote_item']))
        {
            $stripeProductModel = $this->stripeProductFactory->create()->fromQuoteItem($subscription['quote_item']);
        }
        else if (!empty($subscription['order_item']))
        {
            $stripeProductModel = $this->stripeProductFactory->create()->fromOrderItem($subscription['order_item']);
        }
        else
        {
            throw new LocalizedException(__("Could not create subscription product in Stripe."));
        }

        $recurringPrice = $this->createSubscriptionPriceForSubscription($subscription['profile'], $stripeProductModel);

        $items = [];
        $metadata = $this->collectMetadataForSubscription($subscription['profile']);

        $items[] = [
            "metadata" => $metadata,
            "price" => $recurringPrice->id,
            "quantity" => 1
        ];

        return $items;
    }

    /**
     * Returns array [
     *   [
     *     \Magento\Catalog\Model\Product,
     *     \Magento\Sales\Model\Quote\Item,
     *     array $profile
     *   ],
     *   ...
     * ]
     */
    private function getSubscriptionsFromQuote($quote)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return [];

        $items = $quote->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if (!$subscriptionProductModel->isSubscriptionProduct())
                continue;

            $product = $subscriptionProductModel->getProduct();

            try
            {
                $subscriptions[] = [
                    'product' => $product,
                    'quote_item' => $item,
                    'profile' => $this->getSubscriptionDetails($subscriptionProductModel, $quote, $item)
                ];
            }
            catch (\StripeIntegration\Payments\Exception\InvalidSubscriptionProduct $e)
            {
                continue;
            }
        }

        return $subscriptions;
    }

    public function getSubscriptionFromQuote($quote)
    {
        $subscriptions = $this->getSubscriptionsFromQuote($quote);

        if (empty($subscriptions))
        {
            return null;
        }

        if (count($subscriptions) > 1)
        {
            throw new LocalizedException(__("Only one subscription is allowed per order."));
        }

        return array_pop($subscriptions);
    }

    /**
     * Returns array [
     *   [
     *     \Magento\Catalog\Model\Product,
     *     \Magento\Sales\Model\Order\Item,
     *     array $profile
     *   ],
     *   ...
     * ]
     */
    public function getSubscriptionsFromOrder($order)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return [];

        $items = $order->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);
            if (!$subscriptionProductModel->isSubscriptionProduct())
                continue;

            $product = $subscriptionProductModel->getProduct();

            try
            {
                $subscriptions[] = [
                    'product' => $product,
                    'order_item' => $item,
                    'profile' => $this->getSubscriptionDetails($subscriptionProductModel, $order, $item)
                ];
            }
            catch (\StripeIntegration\Payments\Exception\InvalidSubscriptionProduct $e)
            {
                continue;
            }
        }

        return $subscriptions;
    }

    public function getSubscriptionProductFromOrder($order)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return [];

        $items = $order->getAllItems();

        foreach ($items as $item) {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);
            if (!$subscriptionProductModel->isSubscriptionProduct())
                continue;

            return [
                'product' => $subscriptionProductModel->getProduct(),
                'order_item' => $item
            ];
        }

        return null;
    }

    public function getSubscriptionFromOrder($order)
    {
        $subscriptions = $this->getSubscriptionsFromOrder($order);

        if (empty($subscriptions))
        {
            return null;
        }

        if (count($subscriptions) > 1)
        {
            throw new LocalizedException(__("Only one subscription is allowed per order."));
        }

        return array_pop($subscriptions);
    }

    public function getQuote()
    {
        $quote = $this->quoteHelper->getQuote();
        $createdAt = $quote->getCreatedAt();
        if (empty($createdAt)) // case of admin orders
        {
            $quoteId = $quote->getQuoteId();
            $quote = $this->quoteHelper->loadQuoteById($quoteId);
        }
        return $quote;
    }

    public function isOrder($order)
    {
        if (!empty($order->getOrderCurrencyCode()))
            return true;

        return false;
    }

    private function getProductOptionFor($item)
    {
        if (!$item->getParentItem())
            return null;

        $name = $item->getName();

        if ($productOptions = $item->getParentItem()->getProductOptions())
        {
            if (!empty($productOptions["bundle_options"]))
            {
                foreach ($productOptions["bundle_options"] as $bundleOption)
                {
                    if (!empty($bundleOption["value"]))
                    {
                        foreach ($bundleOption["value"] as $value)
                        {
                            if ($value["title"] == $name)
                            {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    public function getVisibleSubscriptionItem($item)
    {
        if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
        {
            return $item->getParentItem();
        }
        else if ($item->getParentItem() && $item->getParentItem()->getProductType() == "bundle")
        {
            return $item->getParentItem();
        }
        else
            return $item;
    }

    // Initial fee amounts take into account the QTY ordered
    public function getInitialFeeDetails($product, $order, $item)
    {
        $details = [
            'initial_fee' => 0,
            'base_initial_fee' => 0,
            'tax' => 0,
            'base_tax' => 0
        ];

        if ($order->getPayment() && $order->getPayment()->getAdditionalInformation("remove_initial_fee"))
        {
            return $details;
        }

        $subscriptionOptionDetails = $this->getSubscriptionOptionDetails($product->getId());
        if (!$subscriptionOptionDetails)
        {
            return $details;
        }

        $initialFee = is_numeric($subscriptionOptionDetails->getSubInitialFee()) ? $subscriptionOptionDetails->getSubInitialFee() : 0;
        if (!$initialFee)
        {
            return $details;
        }

        $originalItem = $item;
        $originalQty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());

        $item = $this->getVisibleSubscriptionItem($item);
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());

        if ($item->getProductType() == "bundle")
        {
            $subSelectionQty = $originalQty;
            $bundleOption = $this->getProductOptionFor($originalItem);

            if ($item->getQtyOptions())
            {
                // Case hits when adding a product to the cart
                $details['base_initial_fee'] = 0;
                foreach ($item->getQtyOptions() as $qtyOption)
                {
                    if ($qtyOption->getProductId() == $originalItem->getProductId())
                    {
                        $subSelectionQty = $qtyOption->getValue();
                    }
                }
            }
            else if (isset($bundleOption['qty']) && is_numeric($bundleOption['qty']) && $bundleOption['qty'] > 0)
            {
                // Case hits in the admin area
                $subSelectionQty = $bundleOption['qty'];
            }

            $details['base_initial_fee'] = $initialFee * $subSelectionQty * $qty;
        }
        else
        {
            $details['base_initial_fee'] = $initialFee * $qty;
        }

        if (!is_numeric($details['base_initial_fee']))
            $details['base_initial_fee'] = 0;

        $taxPercent = $item->getTaxPercent();
        if (!$item->getTaxPercent() && $originalItem->getTaxPercent())
        {
            // Hits in the test suite
            $taxPercent = $originalItem->getTaxPercent();
        }

        if ($this->isOrder($order))
        {
            $rate = $order->getBaseToOrderRate();
        }
        else
        {
            $rate = $order->getBaseToQuoteRate();
        }

        if (is_numeric($rate) && $rate > 0)
        {
            $details['initial_fee'] = round(floatval($details['base_initial_fee'] * $rate), 2);
        }
        else
        {
            $details['initial_fee'] = $details['base_initial_fee'];
        }

        if ($this->config->priceIncludesTax())
        {
            $details['base_tax'] = $this->taxHelper->taxInclusiveTaxCalculator($details['base_initial_fee'], $taxPercent);
            $details['tax'] = $this->taxHelper->taxInclusiveTaxCalculator($details['initial_fee'], $taxPercent);
        }
        else
        {
            $details['base_tax'] = $this->taxHelper->taxExclusiveTaxCalculator($details['base_initial_fee'], $taxPercent);
            $details['tax'] = $this->taxHelper->taxExclusiveTaxCalculator($details['initial_fee'], $taxPercent);
        }

        $details['initial_fee'] = round(floatval($details['initial_fee']), 4);
        $details['base_initial_fee'] = round(floatval($details['base_initial_fee']), 4);
        $details['tax'] = round(floatval($details['tax']), 4);
        $details['base_tax'] = round(floatval($details['base_tax']), 4);

        return $details;
    }

    public function getSubscriptionDetails(\StripeIntegration\Payments\Model\SubscriptionProduct $subscriptionProductModel, $order, $item)
    {
        if (!$subscriptionProductModel->isSubscriptionProduct())
        {
            throw new InvalidSubscriptionProduct("This is not a subscription product.");
        }

        $originalItem = $item;

        $item = $this->getVisibleSubscriptionItem($item);

        $baseCurrency = $order->getBaseCurrencyCode();
        $deductedOrderAmount = 0;
        $baseDeductedOrderAmount = 0;

        if ($this->isOrder($order))
        {
            $orderIncrementId = $order->getIncrementId();
            $currency = $order->getOrderCurrencyCode();
            $qty = $item->getQtyOrdered();
            $subscriptionCart = $this->subscriptionCartFactory->create()->fromOrderItem($originalItem, $order);
        }
        else
        {
            $quote = $order;
            $orderIncrementId = $quote->getReservedOrderId();
            $currency = $quote->getQuoteCurrencyCode();
            $qty = $item->getQty();
            $subscriptionCart = $this->subscriptionCartFactory->create()->fromQuoteItem($originalItem, $quote);
        }

        $currencyPrecision = $this->convert->getCurrencyPrecision($currency);
        $baseCurrencyPrecision = $this->convert->getCurrencyPrecision($baseCurrency);
        $amount = $subscriptionCart->getSubscriptionPrice();
        $baseAmount = $subscriptionCart->getBaseSubscriptionPrice();
        $baseTax = $subscriptionCart->getBaseTaxAmount();
        $tax = $subscriptionCart->getTaxAmount();
        $shipping = $subscriptionCart->getShippingAmount();
        $baseShipping = $subscriptionCart->getBaseShippingAmount();
        $shippingTaxAmount = $subscriptionCart->getShippingTaxAmount();
        $shippingTaxPercent = $subscriptionCart->getShippingTaxPercent();
        $baseShippingTaxAmount = $subscriptionCart->getBaseShippingTaxAmount();
        $discount = $subscriptionCart->getDiscountAmount();
        $baseDiscount = $subscriptionCart->getBaseDiscountAmount();

        if ($subscriptionProductModel->hasZeroInitialOrderPrice() && $this->checkoutFlow->shouldNotBillTrialSubscriptionItems())
        {
            $deductedOrderAmount = $subscriptionCart->getGrandTotal() - $originalItem->getInitialFee();
            $baseDeductedOrderAmount = $subscriptionCart->getBaseGrandTotal() - $originalItem->getBaseInitialFee();

            if (!$this->config->priceIncludesTax())
            {
                $deductedOrderAmount -= $originalItem->getInitialFeeTax();
                $baseDeductedOrderAmount -= $originalItem->getBaseInitialFeeTax();
            }

            $subscriptionCart->setOriginalSubscriptionPrice($order);
        }

        $expiringCouponModel = $this->orderHelper->getExpiringCoupon($order);

        $params = [
            'name' => $item->getName(),
            'qty' => $qty,
            'interval' => $subscriptionProductModel->getInterval(),
            'interval_count' => $subscriptionProductModel->getIntervalCount(),
            'amount_magento' => $amount,
            'base_amount_magento' => $baseAmount,
            'amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($amount, $currency),
            'initial_fee_magento' => $originalItem->getInitialFee(),
            'base_initial_fee_magento' => $originalItem->getBaseInitialFee(),
            'tax_amount_initial_fee' => $originalItem->getInitialFeeTax(),
            'base_tax_amount_initial_fee' => $originalItem->getBaseInitialFeeTax(),
            'initial_fee_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($originalItem->getInitialFee(), $currency),
            'tax_amount_initial_fee_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($originalItem->getInitialFeeTax(), $currency),
            'discount_amount_magento' => $discount,
            'base_discount_amount_magento' => $baseDiscount,
            'discount_amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($discount, $currency),
            'shipping_magento' => round(floatval($shipping), $currencyPrecision),
            'base_shipping_magento' => round(floatval($baseShipping), $baseCurrencyPrecision),
            'shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shipping, $currency),
            'currency' => strtolower($currency),
            'base_currency' => strtolower($baseCurrency),
            'tax_percent' => $item->getTaxPercent(),
            'tax_percent_shipping' => $shippingTaxPercent,
            'tax_amount_item' => $tax, // already takes $qty into account
            'base_tax_amount_item' => round(floatval($baseTax), $baseCurrencyPrecision), // already takes $qty into account
            'tax_amount_item_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($tax, $currency), // already takes $qty into account
            'tax_amount_shipping' => round(floatval($shippingTaxAmount), $currencyPrecision),
            'base_tax_amount_shipping' => round(floatval($baseShippingTaxAmount), $baseCurrencyPrecision),
            'tax_amount_shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shippingTaxAmount, $currency),
            'trial_end' => null,
            'trial_days' => $subscriptionProductModel->getTrialDays() ?? 0,
            'expiring_coupon' => ($expiringCouponModel ? $expiringCouponModel->getData() : null),
            'expiring_tax_amount_item' => 0,
            'expiring_base_tax_amount_item' => 0,
            'expiring_discount_amount_magento' => 0,
            'expiring_base_discount_amount_magento' => 0,
            'product_id' => $subscriptionProductModel->getProductId(),
            'deducted_order_amount' => $deductedOrderAmount,
            'base_deducted_order_amount' => $baseDeductedOrderAmount,
            'order_increment_id' => $orderIncrementId,
        ];

        $subscriptionOrderTotal = $this->getSubscriptionOrderTotalFromSubscriptionDetails($params);
        $params['customer_balance_adjustment'] = $subscriptionCart->getCustomerBalanceAdjustment($order, $subscriptionOrderTotal, false);
        $baseSubscriptionOrderTotal = $this->getBaseSubscriptionOrderTotalFromSubscriptionDetails($params);
        $params['base_customer_balance_adjustment'] = $subscriptionCart->getCustomerBalanceAdjustment($order, $baseSubscriptionOrderTotal);

        $params = array_merge($params, $subscriptionProductModel->getSubscriptionDetails()->getData());

        if (!empty($params['expiring_coupon']))
        {
            // When the coupon expires, we want to increase the tax to the non-discounted amount, so we overwrite it here
            $taxAmountItem = round($params['amount_magento'] * $params['qty'] * ($params['tax_percent'] / 100), $currencyPrecision);
            $baseTaxAmountItem = round($params['base_amount_magento'] * $params['qty'] * ($params['tax_percent'] / 100), $baseCurrencyPrecision);
            $taxAmountItemStripe = $this->paymentsHelper->convertMagentoAmountToStripeAmount($taxAmountItem, $params['currency']);

            $diffTaxAmountItem = $taxAmountItem - $params['tax_amount_item'];
            $diffBaseTaxAmountItem = $baseTaxAmountItem - $params['base_tax_amount_item'];
            $diffTaxAmountItemStripe = $taxAmountItemStripe - $params['tax_amount_item_stripe'];

            // Increase the tax
            $params['tax_amount_item'] += $diffTaxAmountItem;
            $params['base_tax_amount_item'] += $diffBaseTaxAmountItem;
            $params['tax_amount_item_stripe'] += $diffTaxAmountItemStripe;

            // And also increase the discount to cover the tax of the non-discounted amount
            $params['discount_amount_magento'] += $diffTaxAmountItem;
            $params['base_discount_amount_magento'] += $diffBaseTaxAmountItem;
            $params['discount_amount_stripe'] += $diffTaxAmountItemStripe;

            // Set the expiring amount adjustments so that they offset the totals displayed at the front-end
            $params['expiring_tax_amount_item'] = $diffTaxAmountItem;
            $params['expiring_base_tax_amount_item'] = $diffBaseTaxAmountItem;
            $params['expiring_discount_amount_magento'] = $diffTaxAmountItem;
            $params['expiring_base_discount_amount_magento'] = $diffBaseTaxAmountItem;
        }

        return $params;
    }

    public function getTrialDays($product)
    {
        $subscriptionOptionDetails = $this->getSubscriptionOptionDetails($product->getId());
        $trialDays = $subscriptionOptionDetails->getSubTrial();
        if (!empty($trialDays) && is_numeric($trialDays) && $trialDays > 0)
            return $trialDays;

        return 0;
    }

    public function getSubscriptionTotalFromProfile($profile)
    {
        $subscriptionTotal =
            ($profile['qty'] * $profile['amount_magento']) +
            $profile['shipping_magento'] -
            $profile['discount_amount_magento'];

        if (!$this->config->shippingIncludesTax())
            $subscriptionTotal += $profile['tax_amount_shipping']; // Includes qty calculation

        if (!$this->config->priceIncludesTax())
            $subscriptionTotal += $profile['tax_amount_item']; // Includes qty calculation

        $currencyPrecision = $this->convert->getCurrencyPrecision($profile['currency']);
        $total = round(floatval($subscriptionTotal), $currencyPrecision);

        return $total;
    }

    public function getBaseSubscriptionTotalFromProfile($profile)
    {
        $subscriptionTotal =
            ($profile['qty'] * $profile['base_amount_magento']) +
            $profile['base_shipping_magento'] -
            $profile['base_discount_amount_magento'];

        if (!$this->config->shippingIncludesTax())
            $subscriptionTotal += $profile['base_tax_amount_shipping']; // Includes qty calculation

        if (!$this->config->priceIncludesTax())
            $subscriptionTotal += $profile['base_tax_amount_item']; // Includes qty calculation

        $currencyPrecision = $this->convert->getCurrencyPrecision($profile['base_currency']);
        $total = round(floatval($subscriptionTotal), $currencyPrecision);

        return $total;
    }

    // We increase the subscription price by the amount of the discount, so that we can apply
    // a discount coupon on the amount and go back to the original amount AFTER the discount is applied
    public function getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile)
    {
        $total = $this->getSubscriptionTotalFromProfile($profile);

        if (!empty($profile['expiring_coupon']))
            $total += $profile['discount_amount_magento'];

        return $total;
    }

    public function getStripeDiscountAdjustment($subscription)
    {
        $adjustment = 0;

        if (!empty($subscription['profile']))
        {
            $profile = $subscription['profile'];

            // This calculation only applies to MixedTrial carts
            if (!$profile['trial_days'])
                return 0;

            if (!empty($profile['expiring_coupon']))
                $adjustment += $profile['discount_amount_stripe'];
        }

        return $adjustment;
    }

    /**
     * Returns the order total of the subscription, which means that if the order contains things like other products
     * or initial fees for the subscription, they will not be included in the total returned.
     *
     * @param $details
     * @return float
     */
    public function getSubscriptionOrderTotalFromSubscriptionDetails($details)
    {
        $subscriptionsTotal = 0;
        $subscriptionsTotal += $this->getSubscriptionTotalFromProfile($details);
        // If the subscription is either trialing or is set to start on a specified date, the price will be deducted
        $subscriptionsTotal -= $details['deducted_order_amount'];

        // Removes floating point errors
        return round($subscriptionsTotal, 4);
    }

    /**
     * Returns the order total of the subscription, which means that if the order contains things like other products
     * or initial fees for the subscription, they will not be included in the total returned.
     *
     * @param $details
     * @return float
     */
    public function getBaseSubscriptionOrderTotalFromSubscriptionDetails($details)
    {
        $subscriptionsTotal = 0;
        $subscriptionsTotal += $this->getBaseSubscriptionTotalFromProfile($details);
        // If the subscription is either trialing or is set to start on a specified date, the price will be deducted
        $subscriptionsTotal -= $details['base_deducted_order_amount'];

        // Removes floating point errors
        return round($subscriptionsTotal, 4);
    }

    public function updateSubscriptionEntry($subscription, $order)
    {
        $subscriptionModel = $this->subscriptionCollection->getBySubscriptionId($subscription->id);
        $subscriptionModel->initFrom($subscription, $order);
        $this->subscriptionResourceModel->save($subscriptionModel);

        return $subscriptionModel;
    }

    public function getSubscriptionsName($subscriptions)
    {
        $productNames = [];

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];

            if ($profile['qty'] > 1)
                $productNames[] = $profile['qty'] . " x " . $profile['name'];
            else
                $productNames[] = $profile['name'];
        }

        $productName = implode(", ", $productNames);

        $productName = substr($productName, 0, 250);

        return $productName;
    }

    public function createSubscriptionPriceForSubscription($profile, $stripeProductModel)
    {
        if ($this->paymentsHelper->isMultiShipping())
            throw new GenericException("Price ID for multi-shipping subscriptions is not implemented", 1);

        $interval = $profile['interval'];
        $intervalCount = $profile['interval_count'];
        $currency = $profile['currency'];
        $magentoAmount = $this->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);
        $stripeAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

        $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency, $interval, $intervalCount);

        return $stripePriceModel->getStripeObject();
    }


    public function createPriceForOneTimePayment($stripeAmount, $currency)
    {
        $stripeProductModel = $this->stripeProductFactory->create()->fromData("one_time_payment", __("One time payment"));
        $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency);
        return $stripePriceModel->getStripeObject();
    }

    public function collectMetadataForSubscription($profile)
    {
        $subscriptionProductIds = [];

        if (empty($profile['product_id']))
            throw new GenericException("Could not find any subscription product IDs in cart subscriptions.");

        $subscriptionProductIds[] = $profile['product_id'];

        $metadata = [
            "Type" => "SubscriptionsTotal",
            "SubscriptionProductIDs" => implode(",", $subscriptionProductIds)
        ];

        if (!empty($profile['order_increment_id']))
        {
            $metadata["Order #"] = $profile['order_increment_id'];
        }

        return $metadata;
    }

    public function getFutureSubscriptionsDetails($quote = null)
    {
        if ($this->futureSubscriptionsDetails) {
            return $this->futureSubscriptionsDetails;
        }

        if (!$quote) {
            $quote = $this->quoteHelper->getQuote();
        }

        $futureSubscriptionsDetails = [
            'title' => '',
            'start_date_label' => '',
            'frequency_label' => '',
            'formatted_amount' => '',
        ];

        if (!$quote)
            return $futureSubscriptionsDetails;

        $this->futureSubscriptionsDetails = $futureSubscriptionsDetails;

        $items = $quote->getAllItems();
        foreach ($items as $item) {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);

            if (!$subscriptionProductModel->isSubscriptionProduct()) {
                continue;
            }

            if (!$subscriptionProductModel->hasTrialPeriod() && !$subscriptionProductModel->hasStartDate()) {
                continue;
            }

            try
            {
                $profile = $this->getSubscriptionDetails($subscriptionProductModel, $quote, $item);
            }
            catch (\StripeIntegration\Payments\Exception\InvalidSubscriptionProduct $e)
            {
                continue;
            }

            $this->futureSubscriptionsDetails['title'] = __('Subscription Total');

            $startDate = $this->startDateFactory->create()->fromProfile($profile)->getStartDateTimestamp();
            $this->futureSubscriptionsDetails['start_date_label'] = $subscriptionProductModel->getStartDateLabel($startDate) ?? "";
            $this->futureSubscriptionsDetails['frequency_label'] = $subscriptionProductModel->getFrequencyLabel();

            $subscriptionPrice = $this->getSubscriptionTotalFromProfile($profile);
            $this->futureSubscriptionsDetails['formatted_amount'] = $this->currencyHelper->addCurrencySymbol($subscriptionPrice, $profile['currency']);
        }

        return $this->futureSubscriptionsDetails;
    }

    public function formatInterval($stripeAmount, $currency, $intervalCount, $intervalUnit)
    {
        $amount = $this->currencyHelper->formatStripePrice($stripeAmount, $currency);

        if ($intervalCount > 1)
            return __("%1 every %2 %3", $amount, $intervalCount, $intervalUnit . "s");
        else
            return __("%1 every %2", $amount, $intervalUnit);
    }

    public function getSubscriptionProductIDs($subscription)
    {
        $productIDs = [];

        if (isset($subscription->metadata->{"Product ID"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"Product ID"});
        }
        else if (isset($subscription->metadata->{"SubscriptionProductIDs"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"SubscriptionProductIDs"});
        }

        return $productIDs;
    }

    public function getSubscriptionOrderID(\Stripe\Subscription $subscription)
    {
        if (isset($subscription->metadata->{"Order #"}))
        {
            return $subscription->metadata->{"Order #"};
        }

        return null;
    }

    public function getInvoiceAmount(\Stripe\Subscription $subscription)
    {
        $total = 0;
        $currency = null;

        if (empty($subscription->items->data))
            return __("Billed");

        foreach ($subscription->items->data as $item)
        {
            $amount = 0;
            $qty = $item->quantity;

            if (!empty($item->price->type) && $item->price->type != "recurring")
                continue;

            if (!empty($item->price->unit_amount))
                $amount = $qty * $item->price->unit_amount;

            if (!empty($item->price->currency))
                $currency = $item->price->currency;

            if (!empty($item->tax_rates[0]->percentage))
            {
                $rate = 1 + $item->tax_rates[0]->percentage / 100;
                $amount = $rate * $amount;
            }

            $total += $amount;
        }

        return $this->currencyHelper->formatStripePrice($total, $currency);
    }

    public function formatDelivery(\Stripe\Subscription $subscription)
    {
        $interval = $subscription->plan->interval;
        $count = $subscription->plan->interval_count;

        if ($count > 1)
            return __("every %1 %2", $count, __($interval . "s"));
        else
            return __("every %1", __($interval));
    }

    public function getUpcomingInvoice()
    {
        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();
        if (!$subscriptionUpdateDetails)
            return null;

        $items = [];
        if ($subscriptionUpdateDetails && !empty($subscriptionUpdateDetails['_data']['subscription_id']))
        {
            $oldSubscriptionId = $subscriptionUpdateDetails['_data']['subscription_id'];
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($oldSubscriptionId);
            $invoicePreview = $stripeSubscriptionModel->getUpcomingInvoiceAfterUpdate();
            $quote = $this->quoteHelper->getQuote();
            $remainingAmount = $subscriptionAmount = 0;
            $labels = [
                'remaining' => null,
                'subscription' => null
            ];

            $comments = [];

            foreach ($invoicePreview->lines->data as $invoiceItem)
            {
                $stripePriceModel = $this->stripePriceFactory->create()->fromPriceId($invoiceItem->price);
                $invoiceItemMagentoAmount = $this->currencyHelper->formatStripePrice($invoiceItem->amount, $invoiceItem->currency);
                if ($invoiceItemMagentoAmount == "-")
                {
                    // Add negative amount at the end
                    $comments[] = $invoiceItemMagentoAmount . " " . lcfirst($invoiceItem->description);
                }
                else
                {
                    // Add positive amounts at the beginning
                    array_unshift($comments, $invoiceItemMagentoAmount . " " . lcfirst($invoiceItem->description));
                }

                $interval = $stripePriceModel->getInterval();
                $intervalCount = $stripePriceModel->getIntervalCount();
                $isSubscription = is_numeric($intervalCount) && !empty($interval);
                if ($isSubscription)
                {
                    $subscriptionAmount += $invoiceItem->amount;
                    $labels['subscription'] = $this->formatInterval(
                        $subscriptionAmount,
                        $invoiceItem->currency,
                        $intervalCount,
                        $interval
                    );
                }
                else if ($invoiceItem->amount > 0)
                {
                    $remainingAmount += $invoiceItem->amount;
                    $labels['remaining'] = $this->currencyHelper->formatStripePrice($remainingAmount, $invoiceItem->currency);
                }
            }

            // Update the order comments
            if (empty($comments))
            {
                $subscriptionUpdateDetails['_data']['comments'] = null;
            }
            else
            {
                $subscriptionUpdateDetails['_data']['comments'] = implode(", ", $comments);
            }

            $checkoutSession->setSubscriptionUpdateDetails($subscriptionUpdateDetails);

            $items["new_price"] = [
                "amount" => $this->convert->StripeAmountToQuoteAmount($quote->getGrandTotal(), $invoicePreview->currency, $quote),
                "currency" => $invoicePreview->currency,
                "label" => $this->currencyHelper->addCurrencySymbol($quote->getGrandTotal(), $invoicePreview->currency)
            ];

            if ($invoicePreview->ending_balance < 0)
            {
                $amount = $this->convert->stripeAmountToQuoteAmount(-$invoicePreview->ending_balance, $invoicePreview->currency, $quote);
                $amount = $this->currencyHelper->addCurrencySymbol($amount, $invoicePreview->currency);
                $items['credit'] = __("Your account's credit of %1 will be used to offset future subscription payments.", $amount);
            }

            return $items;
        }

        return null;
    }

    public function isSubscriptionReactivate()
    {
        return $this->checkoutSessionHelper->isSubscriptionReactivate();
    }

    public function getSubscriptionUpdateDetails()
    {
        return $this->checkoutSessionHelper->getSubscriptionUpdateDetails();
    }

    public function updateSubscription(\Magento\Payment\Model\InfoInterface $payment)
    {
        try
        {
            $subscriptionUpdateDetails = $this->getSubscriptionUpdateDetails();
            $oldSubscriptionId = $subscriptionUpdateDetails['_data']['subscription_id'];
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($oldSubscriptionId);
            $stripeSubscriptionModel->performUpdate($payment);
        }
        catch (LocalizedException $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            throw $e;
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            throw new LocalizedException(__("Sorry, the order could not be placed. Please contact us for assistance."));
        }
    }

    public function cancelSubscriptionUpdate($silent = false)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();

        if (!$subscriptionUpdateDetails)
            return;

        $productNames = [];
        $quote = $this->quoteHelper->getQuote();
        $quoteItems = $quote->getAllVisibleItems();
        foreach ($quoteItems as $quoteItem)
        {
            $productNames[] = $quoteItem->getName();
            $quoteItem->delete();
        }

        $this->quoteHelper->saveQuote($quote);

        if (!$silent)
        {
            if (!empty($productNames))
            {
                $this->paymentsHelper->addWarning(__("The subscription update (%1) has been canceled.", implode(", ", $productNames)));
            }
            else
            {
                $this->paymentsHelper->addWarning(__("The subscription update has been canceled."));
            }
        }

        $checkoutSession->unsSubscriptionUpdateDetails();
    }

    public function loadSubscriptionModelBySubscriptionId($subscriptionId)
    {
        return $this->subscriptionCollection->getBySubscriptionId($subscriptionId);
    }

    // Returns a minimal profile with just price data
    public function getCombinedProfileFromSubscriptions($subscriptions)
    {
        $combinedProfile = [
            "name" => $this->getSubscriptionsName($subscriptions),
            "magento_amount" => 0,
            "stripe_amount" => null,
            "interval" => null,
            "interval_count" => null,
            "currency" => null,
            "product_ids" => []
        ];

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription["profile"];

            if (empty($combinedProfile["currency"]))
            {
                $combinedProfile["currency"] = $profile["currency"];
            }
            else if ($combinedProfile["currency"] != $profile["currency"])
            {
                throw new GenericException("It is not possible to buy multiple subscriptions in different currencies.");
            }

            if (empty($combinedProfile["interval"]))
            {
                $combinedProfile["interval"] = $profile["interval"];
            }
            else if ($combinedProfile["interval"] != $profile["interval"])
            {
                throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));
            }

            if (empty($combinedProfile["interval_count"]))
            {
                $combinedProfile["interval_count"] = $profile["interval_count"];
            }
            else if ($combinedProfile["interval_count"] != $profile["interval_count"])
            {
                throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));
            }

            $combinedProfile["magento_amount"] += $this->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);
            $combinedProfile["product_ids"][] = $profile["product_id"];
        }

        if (!$combinedProfile["currency"])
            throw new GenericException("No subscriptions specified.");

        $combinedProfile["stripe_amount"] = $this->paymentsHelper->convertMagentoAmountToStripeAmount($combinedProfile["magento_amount"], $combinedProfile["currency"]);

        return $combinedProfile;
    }

    public function isZeroAmountOrder($order)
    {
        $orderItems = $order->getAllItems();
        $trialSubscriptions = [];
        foreach ($orderItems as $orderItem)
        {
            try
            {
                $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($orderItem);

                if ($subscriptionProductModel->isSubscriptionProduct() && $subscriptionProductModel->hasTrialPeriod())
                {
                    $trialSubscriptions[] = [
                        'product' => $subscriptionProductModel->getProduct(),
                        'order_item' => $orderItem,
                        'profile' => $this->getSubscriptionDetails($subscriptionProductModel, $order, $orderItem),
                    ];
                }
            }
            catch (\StripeIntegration\Payments\Exception\InvalidSubscriptionProduct $e)
            {
                // Some bundle products cause crashes
                continue;
            }
        }

        $charge = $order->getGrandTotal();

        if (!empty($trialSubscriptions))
        {
            $combinedProfile = $this->getCombinedProfileFromSubscriptions($trialSubscriptions);
            $charge = $order->getGrandTotal() - $combinedProfile['magento_amount'];
        }

        return ($charge < 0.005);
    }

    /**
     * Get subscription option details
     */
    public function getSubscriptionOptionDetails(string $productId): ?\StripeIntegration\Payments\Model\SubscriptionOptions
    {
        $cacheKey = 'stripe_subscription_details_' . $productId;

        if (isset($this->localCache[$cacheKey])) {
            return $this->localCache[$cacheKey];
        }

        $subscriptionDetails = $this->subscriptionOptionsFactory->create()->load($productId);

        if (empty($subscriptionDetails->getProductId()))
        {
            $this->localCache[$cacheKey] = null;
        }
        else
        {
            $this->localCache[$cacheKey] = $subscriptionDetails;
        }

        return $this->localCache[$cacheKey];
    }

    public function generateSubscriptionName($subscription)
    {
        $items = [];

        if (!empty($subscription->plan->product->name))
            return $subscription->plan->product->name;

        if (empty($subscription->items->data))
            return __("Subscription");

        foreach ($subscription->items->data as $item)
        {
            if ($item->quantity > 1)
                $qty = $item->quantity . " x ";
            else
                $qty = "";

            if (!empty($item->price->product->name))
                $items[] = $qty . $item->price->product->name;
        }

        return implode(", ", $items);
    }

    public function hasSubscriptions($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote) || !$quote->getId())
            return false;

        return $this->quoteHelper->hasSubscriptions($quote);
    }

    public function hasFutureSubscriptions($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        if (!$quote || !$quote->getId())
            return false;

        $cacheKey = 'quote_has_future_subscriptions_' . $quote->getId();
        if (isset($this->localCache[$cacheKey])) {
            return $this->localCache[$cacheKey];
        }

        $items = $quote->getAllItems();

        return $this->localCache[$cacheKey] = $this->quoteHelper->hasFutureSubscriptionsIn($items);
    }

    public function hasOnlyTrialSubscriptionsIn($items)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return false;

        $foundAtLeastOneTrialSubscriptionProduct = false;

        foreach ($items as $item)
        {
            if (!in_array($item->getProductType(), ["simple", "virtual", "downloadable", "giftcard"]))
                continue;

            try
            {
                $product = $this->productHelper->getProduct($item->getProductId());
            }
            catch (NoSuchEntityException $e)
            {
                continue;
            }

            $subscriptionOptionDetails = $this->getSubscriptionOptionDetails($product->getId());

            if (!$subscriptionOptionDetails)
                continue;

            $trial = $subscriptionOptionDetails->getSubTrial();
            if (is_numeric($trial) && $trial > 0)
            {
                $foundAtLeastOneTrialSubscriptionProduct = true;
            }
            else
            {
                return false;
            }
        }

        return $foundAtLeastOneTrialSubscriptionProduct;
    }

    public function setSubscriptionUpdateDetails(\Stripe\Subscription $subscription, $productIds)
    {
        $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscription($subscription);

        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $checkoutSession->setSubscriptionUpdateDetails([
            "_data" => [
                "subscription_id" => $subscription->id,
                "original_order_increment_id" => $this->getSubscriptionOrderID($subscription),
                "product_ids" => $productIds,
                "current_period_end" => $stripeSubscriptionModel->getCurrentPeriodEnd(),
                "current_period_start" => $stripeSubscriptionModel->getCurrentPeriodStart()
            ],
            "current_price_label" => $this->getInvoiceAmount($subscription) . " " . $this->formatDelivery($subscription),
            "last_billed_label" => $stripeSubscriptionModel->getLastBilledLabel(),
            "next_billing_date" => $stripeSubscriptionModel->getNextBillingDateLabel()
        ]);
    }

    public function checkCustomerBalancesAvailability(string $whatIsApplied)
    {
        $quote = $this->getQuote();
        $this->cartInfo->setQuote($quote);

        if ($this->cartInfo->hasTrialSubscriptions())
        {
            throw new LocalizedException(__("You can't apply %1 to a trialing subscription.", $whatIsApplied));
        }
        elseif ($this->cartInfo->hasStartDateSubscriptions())
        {
            throw new LocalizedException(__("You can't apply %1 to a subscription with a start date.", $whatIsApplied));
        }
    }

    // When a saved payment method is selected in the Payment Element, it arrives as a confirmation_token.
    // Subscriptions require a concrete payment method ID attached to the customer, so we confirm a SetupIntent
    // with the confirmation_token to produce an attached PaymentMethod that can be used as default_payment_method.
    private function resolveConfirmationTokenToPaymentMethod($order)
    {
        $payment = $order->getPayment();
        $confirmationToken = $payment->getAdditionalInformation("confirmation_token");
        if (empty($confirmationToken))
        {
            return null;
        }

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists(false, $order);
        }

        if (!$this->customer->getStripeId())
        {
            return null;
        }

        // Use the confirmation_token as the idempotency key so that when the front-end retries the order placement
        // after completing 3DS (via handleNextAction on the SetupIntent's client_secret), Stripe returns the same
        // SetupIntent (now succeeded) instead of rejecting because the confirmation_token was already consumed.
        $idempotencyKey = "setup_intent_for_" . $confirmationToken;

        try
        {
            $setupIntent = $this->config->getStripeClient()->setupIntents->create(
                [
                    'confirmation_token' => $confirmationToken,
                    'customer' => $this->customer->getStripeId(),
                    'usage' => 'off_session',
                    'confirm' => true,
                    'return_url' => $this->paymentsHelper->getUrl('checkout'),
                ],
                [
                    'idempotency_key' => $idempotencyKey,
                ]
            );
        }
        catch (\Exception $e)
        {
            return $this->paymentsHelper->throwError("Could not set up the payment method for the subscription: " . $e->getMessage(), $e);
        }

        // Idempotent replay returns the original response body, so on retries we would still see `requires_action`
        // even after the front-end has completed 3DS. Re-retrieve the SetupIntent to observe its live status.
        if ($setupIntent->status === 'requires_action')
        {
            try
            {
                $setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($setupIntent->id);
            }
            catch (\Exception $e)
            {
                return $this->paymentsHelper->throwError("Could not retrieve the payment setup status: " . $e->getMessage(), $e);
            }
        }

        if (!in_array($setupIntent->status, ['succeeded', 'requires_action', 'processing']))
        {
            return $this->paymentsHelper->throwError(__("The payment method could not be set up. Please try again."));
        }

        $paymentMethodId = $setupIntent->payment_method;

        if (empty($paymentMethodId))
        {
            return $this->paymentsHelper->throwError(__("The payment method could not be set up. Please try again."));
        }

        $stripeSetupIntentModel = $this->stripeSetupIntentModelFactory->create()->fromObject($setupIntent);
        if ($stripeSetupIntentModel->requiresMicrodepositVerification())
        {
            $setupIntentDbModel = $this->setupIntentCollection->findByQuoteId($order->getQuoteId());
            $setupIntentDbModel->setSiId($setupIntent->id);
            $setupIntentDbModel->setQuoteId($order->getQuoteId());
            $setupIntentDbModel->setOrderIncrementId($order->getIncrementId());
            $setupIntentDbModel->setPmId($paymentMethodId);
            $setupIntentDbModel->setIsDelayedSubscriptionSetup(true);
            $this->setupIntentResourceModel->save($setupIntentDbModel);
            $payment->setAdditionalInformation("pending_microdeposit_si_id", $setupIntent->id);
            throw new RequiresMicrodepositVerification();
        }
        else
        {
            $this->customer->attachPaymentMethod($paymentMethodId);
        }

        // The confirmation_token is single-use and has been consumed by the SetupIntent above.
        // Swap it for the resolved payment method token so downstream PaymentIntent/SetupIntent
        // confirmations use `payment_method` instead of the consumed `confirmation_token`.
        // If this SetupIntent still requires_action, we intentionally do not throw here.
        // The subscription created later in the flow will expose either a PaymentIntent or
        // a pending SetupIntent, and the existing PaymentElement confirmation logic will
        // surface the single customer authentication challenge from that subscription intent.
        $payment->setAdditionalInformation("token", $paymentMethodId);
        $payment->setAdditionalInformation("confirmation_token", null);

        return $paymentMethodId;
    }
}
