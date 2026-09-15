<?php

namespace StripeIntegration\Payments\Plugin\Quote;

class AddressShippingTotal
{
    private $quoteHelper;
    private $checkoutFlow;
    private $storeHelper;
    private $configHelper;
    private $isSubscriptionsEnabled;

    public function __construct(
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Store $storeHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow
    )
    {
        $this->quoteHelper = $quoteHelper;
        $this->checkoutFlow = $checkoutFlow;
        $this->storeHelper = $storeHelper;
        $this->configHelper = $configHelper;
        $this->isSubscriptionsEnabled = $this->configHelper->getConfigData("payment/stripe_payments_subscriptions/active", $this->storeHelper->getStoreId());
    }

    public function aroundCollect(
        \Magento\Quote\Model\Quote\Address\Total\Shipping $subject,
        callable $proceed,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        \Magento\Quote\Model\Quote\Address\Total $total
    ) {
        if ($this->isSubscriptionsEnabled && $this->checkoutFlow->shouldNotBillTrialSubscriptionItems())
        {
            $items = $shippingAssignment->getItems();
            $nonBillableSubscriptionItems = [];
            $billableItems = [];

            $nonBillableSubscriptionItems = $this->quoteHelper->getNonBillableSubscriptionItems($shippingAssignment->getItems());

            if (!empty($nonBillableSubscriptionItems))
            {
                $billableItems = array_filter($items, function($item) use ($nonBillableSubscriptionItems) {
                    return !in_array($item, $nonBillableSubscriptionItems);
                });

                if (!empty($billableItems))
                {
                    $shippingAssignment->setItems($billableItems);
                    $proceed($quote, $shippingAssignment, $total);
                    $shippingAssignment->setItems($items);
                    $this->checkoutFlow->isQuoteCorrupted = true;
                }
                else
                {
                    $total->setBaseShippingAmount(0);
                    $total->setShippingAmount(0);
                    $this->checkoutFlow->isQuoteCorrupted = true;
                }
            }
            else
            {
                $proceed($quote, $shippingAssignment, $total);
            }

            return $subject;
        }
        else
        {
            $proceed($quote, $shippingAssignment, $total);
            return $subject;
        }
    }
}
