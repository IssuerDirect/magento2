<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class SavePaymentMethod
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Disabled')
            ],
            [
                'value' => 1,
                'label' => __('Ask for customer consent')
            ],
            [
                'value' => 2,
                'label' => __('Save without asking')
            ]
        ];
    }
}
