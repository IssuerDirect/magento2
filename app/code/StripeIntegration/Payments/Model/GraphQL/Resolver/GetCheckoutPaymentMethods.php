<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class GetCheckoutPaymentMethods implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
        if (empty($args['input']['billingAddress'])) {
            throw new GraphQlInputException(__("Please specify the billing address."));
        }

        try
        {
            $billingAddress = json_decode($args['input']['billingAddress'], true);
            $shippingAddress = !empty($args['input']['shippingAddress']) ? json_decode($args['input']['shippingAddress'], true) : null;
            $shippingMethod = !empty($args['input']['shippingMethod']) ? json_decode($args['input']['shippingMethod'], true) : null;
            $couponCode = $args['input']['couponCode'] ?? null;

            $result = $this->api->get_checkout_payment_methods($billingAddress, $shippingAddress, $shippingMethod, $couponCode);
            return $this->serializer->unserialize($result);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
