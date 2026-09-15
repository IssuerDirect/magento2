<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class PlaceOrderFirst implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'card,link', 'label' => __('Disabled')],
            ['value' => '', 'label' => __('Enabled')],
        ];
    }
}