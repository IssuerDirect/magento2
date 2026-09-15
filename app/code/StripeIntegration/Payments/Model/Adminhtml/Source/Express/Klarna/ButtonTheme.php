<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Express\Klarna;

class ButtonTheme
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'dark',
                'label' => __('Dark')
            ],
            [
                'value' => 'light',
                'label' => __('Light')
            ],
            [
                'value' => 'outlined',
                'label' => __('Outlined')
            ]
        ];
    }
}