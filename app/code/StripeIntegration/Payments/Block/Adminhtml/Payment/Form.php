<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Payment;

// Payment method form in the Magento admin area
class Form extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'StripeIntegration_Payments::form/stripe_payments.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig, $data);
    }
}
