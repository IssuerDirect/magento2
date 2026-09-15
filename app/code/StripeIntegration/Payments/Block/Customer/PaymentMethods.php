<?php

namespace StripeIntegration\Payments\Block\Customer;

class PaymentMethods extends \Magento\Framework\View\Element\Template
{
    private $initParams;
    private $helper;
    private $serializer;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        array $data = []
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->initParams = $initParams;

        parent::__construct($context, $data);
    }

    public function getInitParams()
    {
        try
        {
            return $this->initParams->getMyPaymentMethodsParams();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize([]);
        }
    }
}
