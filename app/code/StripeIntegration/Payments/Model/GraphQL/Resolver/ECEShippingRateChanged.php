<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class ECEShippingRateChanged implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    private $api;
    private $serializer;

    public function __construct(
        \StripeIntegration\Payments\Api\Service $api,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
        $this->api = $api;
        $this->serializer = $serializer;
    }

    public function resolve(
        \Magento\Framework\GraphQl\Config\Element\Field $field,
                                                        $context,
        \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (empty($args['input']['address'])) {
            throw new GraphQlInputException(__("Please specify the new address."));
        }

        try
        {
            $shippingId = null;
            if (isset($args['input']['shippingMethodId'])) {
                $shippingId = $args['input']['shippingMethodId'];
            }
            $params =  $this->api->ece_shipping_rate_changed($args['input']['address'], $shippingId);
            return $this->serializer->unserialize($params);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
