<?php

namespace StripeIntegration\Payments\Block\Multishipping;

use StripeIntegration\Payments\Model\Config as StripeConfig;

// Payment method form in the multi-shipping page
class Billing extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'StripeIntegration_Payments::multishipping/billing/payment_element.phtml';
    private $initParams;
    private $helper;
    private $serializer;
    private $stripeConfig;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        StripeConfig $stripeConfig,
        array $data = []
    ) {
        $this->initParams = $initParams;
        $this->helper = $helper;
        $this->stripeConfig = $stripeConfig;
        $this->serializer = $serializer;

        parent::__construct($context, $paymentConfig, $data);
    }

    public function getInitParams()
    {
        try
        {
            $customer = $this->helper->getCustomerModel();

            if (!$customer->existsInStripe())
                $customer->createStripeCustomerIfNotExists();

            return $this->initParams->getMultishippingParams();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize([]);
        }
    }

    public function getCaptureMethod()
    {
        return $this->stripeConfig->getCaptureMethod();
    }
}
