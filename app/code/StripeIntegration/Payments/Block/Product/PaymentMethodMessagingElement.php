<?php

namespace StripeIntegration\Payments\Block\Product;

use Magento\Framework\Serialize\SerializerInterface;
use \Magento\Framework\View\Element\Template;

class PaymentMethodMessagingElement extends Template
{
    private $serializer;
    private $messagingElementHelper;

    public function __construct(
        SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Stripe\PaymentMethodMessagingElement $messagingElementHelper,
        Template\Context $context,
        array $data = []
    ) {
        $this->serializer = $serializer;
        $this->messagingElementHelper = $messagingElementHelper;

        parent::__construct($context, $data);
    }

    public function getPaymentMethodMessagingOptions()
    {
        $productId = $this->getRequest()->getParam('product_id');
        if ($productId === null) {
            $productId = $this->getRequest()->getParam('id');
        }

        $options = $this->messagingElementHelper->getPaymentMethodMessagingOptions($productId);

        return $this->serializer->serialize($options);
    }
}