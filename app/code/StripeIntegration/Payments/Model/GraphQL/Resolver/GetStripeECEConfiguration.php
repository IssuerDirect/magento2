<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class GetStripeECEConfiguration implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
            $location = $this->getLocationConfigName($args['input']['location']);
            $config = $this->api->get_stripe_ece_configuration($location);
            $return = $this->serializer->unserialize($config);

            if ($return['enabled']) {
                // Keep the buttonConfig as json and only parse them in handlers
                $return['buttonConfig'] = $this->serializer->serialize($return['buttonConfig']);
            }

            return $return;
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }

    /**
     * Returns the config name for the initialization location.
     * Created so the GraphQL user will only use 'product', 'minicart' or 'cart' in their implementation.
     *
     * @param $location
     * @return string|null
     */
    private function getLocationConfigName($location)
    {
        switch ($location) {
            case 'cart':
                return 'shopping_cart_page';
            case 'minicart':
                return 'minicart';
            case 'product':
                return 'product_page';
            default:
                return null;
        }
    }
}
