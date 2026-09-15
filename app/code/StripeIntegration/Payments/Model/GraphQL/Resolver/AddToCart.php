<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class AddToCart implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
        if (empty($args['input']['params'])) {
            throw new GraphQlInputException(__("Please specify the parameters for adding a product."));
        }

        try
        {
            $params = json_decode($args['input']['params'], true);
            $shippingId = null;
            if (isset($args['input']['shipping_id'])) {
                $shippingId = $args['input']['shipping_id'];
            }

            if (!isset($params['related_product'])) {
                $params['related_product'] = null;
            }

            $addToCart = $this->api->addToCart($params, $shippingId);
            return $this->serializer->unserialize($addToCart);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
