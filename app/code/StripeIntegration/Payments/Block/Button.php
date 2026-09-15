<?php

namespace StripeIntegration\Payments\Block;

use Magento\Framework\View\Element\Template;

class Button extends Template
{
    private $productId;
    public $config;
    public $initParams;
    private $serializer;
    private $expressCheckoutConfig;

    public function __construct(
        Template\Context $context,
        \Magento\Framework\App\RequestInterface $request,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\ExpressCheckout\Config $expressCheckoutConfig,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->productId = $request->getParam('id', null);
        $this->config = $config;
        $this->expressCheckoutConfig = $expressCheckoutConfig;
        $this->initParams = $initParams;
        $this->serializer = $serializer;
    }

    /**
     * Check Is Block enabled
     * @return bool
     */
    public function isEnabled($location)
    {
        return $this->expressCheckoutConfig->isEnabled($location);
    }

    public function getActiveLocations()
    {
        return $this->serializer->serialize($this->expressCheckoutConfig->getActiveLocations());
    }

    /**
     * Get Button Config
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getButtonConfig()
    {
        $options = $this->expressCheckoutConfig->getButtonOptions();
        return $this->serializer->serialize($options);
    }

    public function getProductId()
    {
        return $this->productId;
    }
}
