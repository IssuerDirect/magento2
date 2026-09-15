<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class DefaultEUCountry
{
    public function toOptionArray()
    {
        return [
            [
                'value' => null,
                'label' => __('-- Disabled --')
            ],
            [
                'value' => 'DE',
                'label' => __('Germany')
            ],
            [
                'value' => 'FR',
                'label' => __('France')
            ],
            [
                'value' => 'IE',
                'label' => __('Ireland')
            ],
            [
                'value' => 'NL',
                'label' => __('Netherlands')
            ],
        ];
    }
}
