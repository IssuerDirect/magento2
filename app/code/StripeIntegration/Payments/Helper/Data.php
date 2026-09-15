<?php

namespace StripeIntegration\Payments\Helper;

use Iterator;

/**
 * Works with data in a generic way.
 * No dependencies on other helper classes.
 * Can be injected into installation scripts, cron jobs, predispatch observers etc.
 */
class Data
{
    public function convertToSetupIntentConfirmParams($paymentIntentConfirmParams)
    {
        $confirmParams = $paymentIntentConfirmParams;

        if (!empty($confirmParams['payment_method_options']))
        {
            foreach ($confirmParams['payment_method_options'] as $key => $value)
            {
                if (isset($confirmParams['payment_method_options'][$key]['setup_future_usage']))
                    unset($confirmParams['payment_method_options'][$key]['setup_future_usage']);

                if (isset($confirmParams['payment_method_options'][$key]['moto']))
                    unset($confirmParams['payment_method_options'][$key]['moto']);

                if (isset($confirmParams['payment_method_options'][$key]['require_cvc_recollection']))
                    unset($confirmParams['payment_method_options'][$key]['require_cvc_recollection']);

                if (!in_array($key, \StripeIntegration\Payments\Helper\PaymentMethod::SETUP_INTENT_PAYMENT_METHOD_OPTIONS))
                    unset($confirmParams['payment_method_options'][$key]);

                if (empty($confirmParams['payment_method_options'][$key]))
                    unset($confirmParams['payment_method_options'][$key]);
            }

            if (empty($confirmParams['payment_method_options']))
                unset($confirmParams['payment_method_options']);
        }

        if (isset($confirmParams['off_session']))
            unset($confirmParams['off_session']);

        return $confirmParams;
    }

    public function getBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        if (empty($productOptions['info_buyRequest']))
            return null;

        return new \Magento\Framework\DataObject($productOptions['info_buyRequest']);
    }

    public function getConfigurableProductBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        $buyRequest = isset($productOptions['info_buyRequest']) ? $productOptions['info_buyRequest'] : null;

        if (!$buyRequest)
            return null;

        $buyRequest['qty'] = $orderItem->getQtyOrdered();

        // Extract the configurable item options
        $configurableItemOptions = isset($productOptions['attributes_info']) ? $productOptions['attributes_info'] : null;

        if (!$configurableItemOptions)
            return $buyRequest;

        // Add the configurable item options to buyRequest
        $superAttribute = [];
        foreach ($configurableItemOptions as $option) {
            if (isset($option['attribute_id']) && isset($option['value'])) {
                $superAttribute[$option['attribute_id']] = $option['value'];
            }
        }

        if (!empty($superAttribute)) {
            $buyRequest['super_attribute'] = $superAttribute;
        }

        return $buyRequest;
    }

    public function getLatestStripeObject(Iterator $collection): ?\Stripe\StripeObject
    {
        $latest = null;
        foreach ($collection as $item)
        {
            if (!isset($item->created))
                continue;

            if ($latest === null || $item->created > $latest->created)
            {
                $latest = $item;
            }
        }
        return $latest;
    }

}
