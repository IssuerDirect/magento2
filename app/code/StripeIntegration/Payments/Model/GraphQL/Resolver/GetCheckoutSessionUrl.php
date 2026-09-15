<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class GetCheckoutSessionUrl implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    private $api;

    public function __construct(
        \StripeIntegration\Payments\Api\Service $api
    ) {
        $this->api = $api;
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
            return $this->api->get_checkout_session_url();
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
