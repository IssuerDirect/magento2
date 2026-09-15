<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class GetECEParams implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
        if (empty($args['input']['location']))
            throw new GraphQlInputException(__("Please specify the location."));

        try
        {
            $productId = null;
            if (isset($args['input']['productId'])) {
                $productId = $args['input']['productId'];
            }
            $attribute = null;
            if (isset($args['input']['attribute'])) {
                $attribute = $args['input']['attribute'];
            }
            $params =  $this->api->ece_params($args['input']['location'], $productId, $attribute);

            return $this->serializer->unserialize($params);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
