<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

// sales_quote_merge_before
class SalesQuoteMergeBeforeObserver implements ObserverInterface
{
    private $config;
    private $quoteHelper;
    private $checkoutSessionHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper
    )
    {
        $this->config = $config;
        $this->quoteHelper = $quoteHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
    }

    public function execute(Observer $observer)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        // Retrieve the quotes from the observer
        $guestQuote = $observer->getEvent()->getSource();
        $destinationQuote = $observer->getEvent()->getQuote();

        if (!$guestQuote || !$destinationQuote)
            return;

        // During a subscription update, the destination quote is the subscription update quote.
        // We reset the guest (source) quote so that no products from it can be merged into the
        // subscription update quote.
        if ($this->checkoutSessionHelper->isSubscriptionUpdate())
        {
            $details = $this->checkoutSessionHelper->getSubscriptionUpdateDetails();
            $products = $details['_data']['product_ids'];

            $guestQuote->removeAllItems();
            $this->quoteHelper->removeNonSubscriptionUpdateItems($destinationQuote, $products);

            return;
        }

        $guestQuoteHasSubscriptions = $this->quoteHelper->hasSubscriptions($guestQuote);
        $destinationQuoteHasSubscriptions = $this->quoteHelper->hasSubscriptions($destinationQuote);

        if ($guestQuoteHasSubscriptions && $destinationQuoteHasSubscriptions)
        {
            // Remove subscriptions from the destination quote, as only one can be purchased at a time.
            $this->quoteHelper->removeSubscriptions($destinationQuote);
        }
    }
}
