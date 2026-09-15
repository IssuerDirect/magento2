<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Payment;

use Magento\Payment\Block\ConfigurableInfo;

class BankTransfers extends ConfigurableInfo
{
    protected $_template = 'StripeIntegration_Payments::form/bank_transfers.phtml';
    private $stripeConfig;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $stripeConfig,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        array $data = []
    ) {
        $this->stripeConfig = $stripeConfig;

        parent::__construct($context, $config, $data);
    }

    public function getDaysDue()
    {
        return $this->stripeConfig->getBankTransfersPendingPaymentOrderLifetime();
    }
}
