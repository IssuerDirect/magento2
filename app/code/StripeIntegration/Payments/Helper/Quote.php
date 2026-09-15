<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\Exception;
use Magento\Framework\Exception\NoSuchEntityException;

class Quote
{
    // $quoteId is set right before the order is placed from inside Plugin/Sales/Model/Service/OrderService,
    // as the GraphQL flow may place an order without a loaded quote. Used for loading the quote later.
    public $quoteId = null;

    private $quotesCache = [];

    private $backendSessionQuote;
    private $checkoutSession;
    private $quoteRepository;
    private $areaCodeHelper;
    private $productHelper;
    private $subscriptionProductFactory;
    private $quoteFactory;
    private $logHelper;
    private $storeManager;
    private $customerSession;
    private $isSubscriptionsEnabled;

    public function __construct(
        \Magento\Backend\Model\Session\Quote $backendSessionQuote,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\Logger $logHelper,
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Store $storeHelper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Model\Session $customerSession
    )
    {
        $this->backendSessionQuote = $backendSessionQuote;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->productHelper = $productHelper;
        $this->logHelper = $logHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
        $this->customerSession = $customerSession;
        $this->isSubscriptionsEnabled = $configHelper->getConfigData("payment/stripe_payments_subscriptions/active", $storeHelper->getStoreId());
    }

    // This method is not inside the subscriptions helper to avoid circular dependencies between Model/Config and other classes.
    public function hasSubscriptions(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        if (!$quote)
            $quote = $this->getQuote();

        $quoteId = $quote->getId();

        if ($quoteId)
        {
            if (isset($this->quotesCache[$quoteId]))
            {
                if ($this->quotesCache[$quoteId]->getHasSubscriptions() !== null)
                {
                    return $this->quotesCache[$quoteId]->getHasSubscriptions();
                }
            }
            else
            {
                $this->quotesCache[$quoteId] = $quote;
            }
        }

        $items = $quote->getAllItems();
        $hasSubscriptions = $this->hasSubscriptionsIn($items);
        $quote->setHasSubscriptions($hasSubscriptions);

        return $hasSubscriptions;
    }

    public function hasSubscriptionsIn($quoteItems)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                return true;
            }
        }

        return false;
    }

    public function getQuote($quoteId = null): \Magento\Quote\Api\Data\CartInterface
    {
        // Admin area new order page
        if ($this->areaCodeHelper->isAdmin())
            return $this->getBackendSessionQuote();

        // Front end checkout
        $quote = $this->getSessionQuote();

        // API Request
        if (empty($quote) || !is_numeric($quote->getGrandTotal()))
        {
            try
            {
                if ($quoteId)
                    $quote = $this->quoteRepository->get($quoteId);
                else if ($this->quoteId) {
                    $quote = $this->quoteRepository->get($this->quoteId);
                }
            }
            catch (\Exception $e)
            {

            }
        }

        return $quote;
    }

    public function getQuoteDescription($quote)
    {
        if ($quote->getCustomerIsGuest())
            $customerName = $quote->getBillingAddress()->getName();
        else
            $customerName = $quote->getCustomerName();

        if (!empty($customerName))
            $description = __("Cart %1 by %2", $quote->getId(), $customerName);
        else
            $description = __("Cart %1", $quote->getId());

        return $description;
    }

    public function loadQuoteById($quoteId)
    {
        if (!is_numeric($quoteId))
            return null;

        if (!empty($this->quotesCache[$quoteId]))
            return $this->quotesCache[$quoteId];

        $this->quotesCache[$quoteId] = $this->quoteFactory->create()->load($quoteId);

        return $this->quotesCache[$quoteId];
    }

    public function loadQuoteByIdWithoutStore($quoteId)
    {
        if (!is_numeric($quoteId))
            return null;

        if (!empty($this->quotesCache[$quoteId]))
            return $this->quotesCache[$quoteId];

        $this->quotesCache[$quoteId] = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);

        return $this->quotesCache[$quoteId];
    }

    private function getBackendSessionQuote()
    {
        return $this->backendSessionQuote->getQuote();
    }

    private function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function saveQuote($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $this->quoteRepository->save($quote);

        return $quote;
    }

    /**
     * Add product to shopping cart (quote)
     */
    public function addProduct($productId, ?array $requestInfo = null)
    {
        if (!$productId)
            throw new \Magento\Framework\Exception\LocalizedException(__('The product does not exist.'));

        try
        {
            $request = $this->prepareBuyRequest($requestInfo);
            $product = $this->productHelper->getProduct($productId);
            $result = $this->getQuote()->addProduct($product, $request);
        }
        catch (NoSuchEntityException $e)
        {
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__("The product wasn't found. Verify the product and try again."));
        }
        catch (\Exception $e)
        {
            $this->logHelper->logError($e->getMessage(), $e->getTraceAsString());
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__("We can't add this item to your shopping cart right now."));
        }

        if (is_string($result))
        {
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__($result));
        }

        $this->checkoutSession->setLastAddedProductId($productId);
        return $result;
    }

    public function removeItem($itemId)
    {
        $item = $this->getQuote()->removeItem($itemId);

        if ($item->getHasError()) {
            throw new \Magento\Framework\Exception\LocalizedException(__($item->getMessage()));
        }

        return $this;
    }

    public function isProductInCart($productId)
    {
        $quote = $this->getQuote();
        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            if ($item->getProductId() == $productId)
                return true;
        }

        return false;
    }

    /**
     * Adding products to cart by ids
     */
    public function addProductsByIds(array $productIds)
    {
        foreach ($productIds as $productId) {
            $this->addProduct($productId);
        }

        return $this;
    }

    public function isMultiShipping($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        return $quote->getIsMultiShipping();
    }

    public function clearCache()
    {
        $this->quotesCache = [];
    }

    public function hasSubscriptionsWithStartDate($quote = null)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        if (!$quote)
            $quote = $this->getQuote();

        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() &&
                $subscriptionProductModel->hasStartDate()
            )
            {
                return true;
            }
        }

        return false;
    }

    public function hasFutureSubscriptionsIn($quoteItems)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() &&
                ($subscriptionProductModel->hasTrialPeriod() || $subscriptionProductModel->hasStartDate())
            ) {
                return true;
            }
        }

        return false;
    }

    public function getNonBillableSubscriptionItems($items)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return [];
        }

        $nonBillableItems = [];

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);

            if (!$subscriptionProductModel->isSubscriptionProduct())
                continue;

            if (!$subscriptionProductModel->hasZeroInitialOrderPrice())
                continue;

            if ($item->getParentItem()) // Bundle and configurable subscriptions
            {
                $item = $item->getParentItem();
                $nonBillableItems[] = $item;

                // Get all child products
                foreach ($items as $item2)
                {
                    if ($item2->getParentItemId() == $item->getId())
                        $nonBillableItems[] = $item2;
                }
            }
            else
            {
                $nonBillableItems[] = $item;
            }
        }

        return $nonBillableItems;
    }

    // Checks if the quote has a 100% discount rule, and that the discount will eventually expire
    public function hasFullyDiscountedSubscriptions($quote)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        $items = $quote->getAllItems();

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);

            if (!$subscriptionProductModel->isSubscriptionProduct())
            {
                continue;
            }

            if ($item->getParentItem())
            {
                $item = $item->getParentItem();
            }

            if ($item->getBasePrice() > 0 && $item->getBasePrice() <= $item->getBaseDiscountAmount())
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the total on the quote is fully being redeemed through a combination of gift cards, store credit and
     * reward points
     *
     * @param $quote
     * @return bool
     * @throws \StripeIntegration\Payments\Exception\InvalidSubscriptionProduct
     */
    public function isZeroTotalSubscriptionFromAdjustment($quote)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        $totalAdjustment = floatval($quote->getRewardCurrencyAmount()) + floatval($quote->getGiftCardsAmountUsed()) + floatval($quote->getCustomerBalanceAmountUsed());

        if ($this->hasSubscriptions($quote)) {
            // The way the adjustments will be used is we assume that first they will be applied to other types of
            // products and then to the subscriptions, so if the grand total is 0 and the adjustment is greater than 0,
            // then a deduction has taken place. If there is a non-billable subscription in the cart, the grand total
            // is still set to what it is before the subscription recurring price is taken out.
            if (($quote->getGrandTotal() == 0) && ($totalAdjustment > 0)) {
                return true;
            }
        }

        return false;
    }

    public function reCollectTotals($quote)
    {
        $shippingMethod = null;
        $quote->getBillingAddress()->unsetData('cached_items_all');
        $quote->getBillingAddress()->unsetData('cached_items_nominal');
        $quote->getBillingAddress()->unsetData('cached_items_nonnominal');
        $quote->getBillingAddress()->unsetData('totals');
        if (!$quote->getIsVirtual())
        {
            $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
            $quote->getShippingAddress()->unsetData('cached_items_all');
            $quote->getShippingAddress()->unsetData('cached_items_nominal');
            $quote->getShippingAddress()->unsetData('cached_items_nonnominal');
            $quote->getShippingAddress()->unsetData('totals');
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }
        foreach ($quote->getAllItems() as $item)
        {
            $item->setTaxCalculationPrice(null);
            $item->setBaseTaxCalculationPrice(null);
        }
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        if ($shippingMethod)
        {
            // We restore it because when the shipping rates are collected, the shipping method is reset
            $quote->getShippingAddress()->setShippingMethod($shippingMethod);
        }
    }

    public function removeSubscriptions(\Magento\Quote\Api\Data\CartInterface $quote)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        $removed = false;
        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                if ($item->getParentItem())
                {
                    $quote->removeItem($item->getParentItem()->getId());
                    $removed = true;
                }
                else
                {
                    $quote->removeItem($item->getId());
                    $removed = true;
                }
            }
        }

        return $removed;
    }

    /**
     * Remove all visible items from the quote that are not part of the subscription being updated.
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @param array $productIds Product IDs that are part of the subscription update
     * @return bool True if any item was removed
     */
    public function removeNonSubscriptionUpdateItems(\Magento\Quote\Api\Data\CartInterface $quote, $productIds)
    {
        $removed = false;

        foreach ($quote->getAllVisibleItems() as $item)
        {
            if (in_array($item->getProductId(), $productIds))
                continue;

            $itemId = $item->getId();
            if ($itemId)
            {
                $quote->removeItem($itemId);
            }
            else
            {
                $item->isDeleted(true);
            }
            $removed = true;
        }

        return $removed;
    }

    public function getQuoteItemFromProductId($productId)
    {
        $quote = $this->getQuote();
        $quoteItems = $quote->getAllItems();

        foreach ($quoteItems as $quoteItem)
        {
            if ($quoteItem->getProductId() == $productId)
            {
                return $quoteItem;
            }
        }

        throw new Exception("Quote item not found for order item");
    }

    public function deactivateQuoteById($quoteId)
    {
        if (empty($quoteId))
            return;

        try
        {
            $quote = $this->quoteRepository->get($quoteId);
            $this->deactivateQuote($quote);
        }
        catch (\Exception $e)
        {

        }
    }

    public function deactivateQuote($quote)
    {
        if (empty($quote) || !$quote->getId())
            return;

        try
        {
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
        }
        catch (\Exception $e)
        {

        }
    }

    public function deactivateCurrentQuote()
    {
        $quote = $this->getQuote();
        if ($quote && $quote->getId())
        {
            $this->deactivateQuote($quote);
        }
    }

    public function createFreshQuote()
    {
        // Create a new empty quote
        $quote = $this->quoteFactory->create();

        // Get store ID and website ID correctly
        $store = $this->storeManager->getStore();
        $storeId = $store->getId();

        // Set store ID and website ID on the quote
        $quote->setStoreId($storeId);
        $quote->setWebsiteId($store->getWebsiteId());
        $quote->setIsActive(true);

        // If customer is logged in, associate the quote with them
        $customerId = $this->customerSession->getCustomer()->getEntityId();
        if ($customerId) {
            $quote->setCustomerId($customerId);
            $quote->setCustomerEmail($this->customerSession->getCustomer()->getEmail());
            $quote->setCustomerIsGuest(0);
        } else {
            $quote->setCustomerIsGuest(1);
        }

        // Save the quote
        $this->quoteRepository->save($quote);

        // Set as active quote in session
        $this->checkoutSession->setQuoteId($quote->getId());
        $this->checkoutSession->replaceQuote($quote);

        // Add to quotes cache
        $this->quotesCache[$quote->getId()] = $quote;

        return $quote;
    }

    private function prepareBuyRequest(?array $requestInfo): \Magento\Framework\DataObject
    {
        if (!empty($requestInfo)) {
            $requestInfo = array_filter($requestInfo, fn($key) => stripos($key, 'custom') === false, ARRAY_FILTER_USE_KEY);
        }

        return new \Magento\Framework\DataObject($requestInfo);
    }
}
