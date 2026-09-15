<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class GetFutureSubscriptions implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
        try
        {
            $billingAddress = !empty($args['input']['billingAddress']) ? json_decode($args['input']['billingAddress'], true) : null;
            $shippingAddress = !empty($args['input']['shippingAddress']) ? json_decode($args['input']['shippingAddress'], true) : null;
            $shippingMethod = !empty($args['input']['shippingMethod']) ? json_decode($args['input']['shippingMethod'], true) : null;

            $result = $this->api->get_future_subscriptions($billingAddress, $shippingAddress, $shippingMethod);
            return $this->serializer->unserialize($result);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
