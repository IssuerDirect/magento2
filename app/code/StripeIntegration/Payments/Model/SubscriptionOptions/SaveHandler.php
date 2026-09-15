<?php
namespace StripeIntegration\Payments\Model\SubscriptionOptions;

use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use StripeIntegration\Payments\Model\SubscriptionOptionsFactory as SubscriptionOptionsModelFactory;
use StripeIntegration\Payments\Exception\LocalizedException;

class SaveHandler implements ExtensionInterface
{
    private SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory;
    private $productExtensionFactory;

    public function __construct(
        SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory,
        \Magento\Catalog\Api\Data\ProductExtensionFactory $productExtensionFactory
    ) {
        $this->subscriptionOptionsModelFactory = $subscriptionOptionsModelFactory;
        $this->productExtensionFactory = $productExtensionFactory;
    }

    /**
     * Save stripe subscription coupon expires values
     *
     * @param object $entity Entity
     * @param array<mixed> $arguments Arguments
     * @return bool|object
     * @throws \Exception
     */
    public function execute($entity, $arguments = [])
    {
        if (!empty($entity->getSubscriptionOptions()) && is_array($entity->getSubscriptionOptions()))
        {
            $input = $entity->getSubscriptionOptions();

            if (isset($input['sub_enabled']) && $input['sub_enabled']) {
                $this->validateMinValue($input, 'sub_interval_count', 'Repeat Every', 1, true);
                $this->validateMinValue($input, 'sub_trial', 'Trial Days', 0);
                $this->validateMinValue($input, 'sub_initial_fee', 'Initial Fee', 0);
            }

            $subscriptionOptionsModel = $this->subscriptionOptionsModelFactory->create()->load($entity->getId());
            $subscriptionOptionsModel->setProductId($entity->getId());

            $subscriptionOptionsModel->addData($input);

            $subscriptionOptionsModel->save();

            $extensionAttributes = $entity->getExtensionAttributes() ?? $this->productExtensionFactory->create();
            if (method_exists($extensionAttributes, 'setSubscriptionOptions'))
            {
                $extensionAttributes->setSubscriptionOptions($subscriptionOptionsModel);
                $entity->setExtensionAttributes($extensionAttributes);
            }
        }

        return $entity;
    }

    /**
     * Added for checking the minimum value the subscription fields can have
     *
     * @param array<mixed> $input
     * @throws LocalizedException
     */
    private function validateMinValue(array $input, string $field, string $label, float $min, bool $required = false): void
    {
        if ((!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) && !$required)
        {
            return;
        }

        if ((float)$input[$field] < $min)
        {
            throw new LocalizedException(__('%1 must be greater than or equal to %2.', $label, $min));
        }
    }
}
