<?php
namespace StripeIntegration\Payments\Plugin\Quote;

use Magento\Quote\Model\Quote as QuoteEntity;
use Magento\Framework\Exception\LocalizedException;

class QuoteManagement
{
    private $checkoutFlow;
    private $quoteHelper;
    private $config;
    private $cartInfo;
    private $quoteResourceModel;
    private $checkoutSessionHelper;
    private $orderHelper;
    private $webhookEventCollectionFactory;
    private $loggerHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\Cart\Info $cartInfo,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory,
        \Magento\Quote\Model\ResourceModel\Quote $quoteResourceModel
    )
    {
        $this->checkoutFlow = $checkoutFlow;
        $this->cartInfo = $cartInfo;
        $this->quoteHelper = $quoteHelper;
        $this->config = $config;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->orderHelper = $orderHelper;
        $this->loggerHelper = $loggerHelper;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
        $this->quoteResourceModel = $quoteResourceModel;
    }

    /**
     * Wrap the full quote submit (order place + quote_submit_success email observers).
     * The placement lock must cover the email send, which happens AFTER place() returns.
     * Deferred webhooks are processed only after the lock is released.
     */
    public function aroundSubmit(
        \Magento\Quote\Model\QuoteManagement $subject,
        \Closure $proceed,
        QuoteEntity $quote,
        $orderData = []
    )
    {
        $paymentMethodCode = (string)$quote->getPayment()->getMethod();
        $isStripe = strstr($paymentMethodCode, 'stripe_') !== false;
        $quoteId = $quote->getId();
        $order = null;

        if ($isStripe)
        {
            // Avoid order number skipping in the case of payment failures
            $quote->reserveOrderId();

            // We intentionally do not use the quote repository for saving, because that would trigger
            // a shipping rate recollection which will reset the selected shipping method
            $this->quoteResourceModel->save($quote);

            // Build info details about the quote
            $this->cartInfo->setQuote($quote);

            // Adjust quote totals to account for trial subscriptions and subscriptions with start dates
            $this->checkoutFlow->isNewOrderBeingPlaced = true;
            $this->checkoutFlow->isSubscriptionUpdate = $this->checkoutSessionHelper->isSubscriptionUpdate();

            if ($this->checkoutFlow->isSubscriptionUpdate)
            {
                $this->validateSubscriptionUpdateQuote($quote);
            }

            $this->setTrialSubscriptionCustomPrice($quote);
            $this->quoteHelper->reCollectTotals($quote);

            // Held until submit finishes (including SubmitObserver order email on quote_submit_success).
            // Concurrent webhooks queue via OrderPlacementInProgressException and are flushed below.
            if ($quoteId)
            {
                $this->orderHelper->acquireOrderPlacementLock($quoteId, 0);
            }
        }

        try
        {
            $order = $proceed($quote, $orderData);
            return $order;
        }
        finally
        {
            if ($isStripe)
            {
                $this->checkoutFlow->isNewOrderBeingPlaced = false;

                if ($quoteId)
                {
                    $this->orderHelper->releaseOrderPlacementLock($quoteId);
                }

                // After email + lock release: process events that arrived early or while locked.
                if ($order && $order->getId())
                {
                    $this->processDeferredWebhookEvents($order);
                }
            }
        }
    }

    // A subscription update order must only include the subscriptions to be updated.
    private function validateSubscriptionUpdateQuote($quote)
    {
        $details = $this->checkoutSessionHelper->getSubscriptionUpdateDetails();
        $products = $details['_data']['product_ids'];

        foreach ($quote->getAllVisibleItems() as $item)
        {
            if (!in_array($item->getProductId(), $products))
            {
                throw new LocalizedException(__('You cannot add products to the cart while a subscription change is in progress.'));
            }
        }
    }

    /**
     * Process webhook events deferred during placement (order not found yet, or placement lock held).
     * Must run only after the placement lock is released.
     */
    private function processDeferredWebhookEvents($order): void
    {
        try
        {
            $method = $order->getPayment() ? $order->getPayment()->getMethod() : null;
            if ($method !== 'stripe_payments' && $method !== 'stripe_payments_express')
                return;

            $transactionId = $order->getPayment()->getAdditionalInformation('server_side_transaction_id');
            if (empty($transactionId))
                return;

            $events = $this->webhookEventCollectionFactory->create()->getEarlyEventsForPaymentIntentId($transactionId, [
                'charge.succeeded',
                'invoice.payment_succeeded',
                'setup_intent.succeeded'
            ]);

            foreach ($events as $eventModel)
            {
                try
                {
                    $eventModel->process($this->config->getStripeClient());
                }
                catch (\Exception $e)
                {
                    $eventModel->refresh()->setLastErrorFromException($e);
                }
            }
        }
        catch (\Exception $e)
        {
            $this->loggerHelper->logError(
                "Failed to process deferred webhook events for order "
                . $order->getIncrementId() . ": " . $e->getMessage(),
                $e->getTraceAsString()
            );
        }
    }

    private function setTrialSubscriptionCustomPrice($quote)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        if (!$this->checkoutFlow->shouldNotBillTrialSubscriptionItems())
            return;

        $items = $this->quoteHelper->getNonBillableSubscriptionItems($quote->getAllItems());
        foreach ($items as $item)
        {
            $item->setCustomPrice(0);
            $item->setOriginalCustomPrice(0);
            $item->getProduct()->setIsSuperMode(true);
            $this->checkoutFlow->isQuoteCorrupted = true; // Because the subtotal is wrong

            // Save a reference of the original prices
            if (!$item->getStripeOriginalSubscriptionPrice())
            {
                if ($this->config->priceIncludesTax())
                {
                    $item->setStripeOriginalSubscriptionPrice($item->getPriceInclTax());
                }
                else
                {
                    $item->setStripeOriginalSubscriptionPrice($item->getConvertedPrice());
                }
            }
        }
    }
}
