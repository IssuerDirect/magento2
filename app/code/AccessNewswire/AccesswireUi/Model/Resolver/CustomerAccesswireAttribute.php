<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model\Resolver;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves a `Customer` field to the customer's custom attribute of the same
 * name (accesswire_account_id, accesswire_account_name).
 */
class CustomerAccesswireAttribute implements ResolverInterface
{
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $customer = $value['model'] ?? null;
        if (!$customer instanceof CustomerInterface) {
            return null;
        }

        return $customer->getCustomAttribute($field->getName())?->getValue();
    }
}
