<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Express\Klarna;

class ButtonType
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'pay',
                'label' => __('Pay')
            ],
            [
                'value' => 'continue',
                'label' => __('Continue')
            ]
        ];
    }
}
