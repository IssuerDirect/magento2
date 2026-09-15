<?php

namespace StripeIntegration\Tax\Plugin\Quote\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentInterfaceFactory;
use Magento\Quote\Api\Data\TotalsInterface;

class CartTotalRepositoryPlugin
{
    private CartRepositoryInterface $quoteRepository;
    private TotalSegmentInterfaceFactory $totalSegmentFactory;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        TotalSegmentInterfaceFactory $totalSegmentFactory
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->totalSegmentFactory = $totalSegmentFactory;
    }

    /**
     * Stripe flat fees are stored as a JSON blob on the quote address by the StripeFlatFees
     * total collector. The cart and checkout totals are rendered via JS components that read
     * named total segments from the quote totals API response. Since the collector only adds
     * the fee amounts to the grand total, they are not visible as individual line items by
     * default. This plugin injects one TotalSegment per fee type so the JS components can
     * discover and display them.
     */
    public function afterGet(
        \Magento\Quote\Api\CartTotalRepositoryInterface $subject,
        TotalsInterface $result,
        $cartId
    ): TotalsInterface {
        // Account for the case where the quote is not found
        try {
            $quote = $this->quoteRepository->getActive($cartId);
        } catch (NoSuchEntityException $e) {
            return $result;
        }

        $address = $quote->isVirtual()
            ? $quote->getBillingAddress()
            : $quote->getShippingAddress();

        $feesJson = $address->getData('stripe_flat_fees');
        if (!$feesJson) {
            return $result;
        }

        $fees = json_decode($feesJson, true) ?? [];
        $segments = $result->getTotalSegments();

        foreach ($fees as $taxType => $feeData) {
            $amount = $feeData['amount'] ?? 0;
            if ($amount <= 0) {
                continue;
            }

            $segment = $this->totalSegmentFactory->create();
            $segment->setCode('stripe_flat_fee_' . $taxType);
            $segment->setTitle(ucwords(str_replace('_', ' ', $taxType)));
            $segment->setValue($amount);

            $segments['stripe_flat_fee_' . $taxType] = $segment;
        }

        $result->setTotalSegments($segments);

        return $result;
    }
}
