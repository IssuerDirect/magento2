<?php
declare(strict_types=1);

namespace AccessNewswire\Customer\Setup\Patch\Data;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * The AccessWire account's company name, alongside its ID (see
 * AddAccesswireAccountIdAttribute) - for recognising the account in the
 * admin, not for lookups.
 */
class AddAccesswireAccountNameAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTRIBUTE_CODE = 'accesswire_account_name';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public function apply(): self
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'varchar',
                'label' => 'AccessWire Account Name',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                // Defaults to true if omitted. System attributes are left out
                // of `custom_attributes`, which is the only way the REST API
                // and CustomerInterface can read or write this one.
                'system' => false,
                'user_defined' => true,
                'position' => 1001,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'is_searchable_in_grid' => true,
            ]
        );

        // Without being in the default attribute set and the admin form, the
        // attribute exists but can't be saved from the admin or the API.
        $attribute = $customerSetup->getEavConfig()->getAttribute(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            self::ATTRIBUTE_CODE
        );
        $attributeSetId = $customerSetup->getDefaultAttributeSetId(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER
        );
        $attribute->addData([
            'attribute_set_id' => $attributeSetId,
            'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(
                CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
                $attributeSetId
            ),
            'used_in_forms' => ['adminhtml_customer'],
        ]);
        $attribute->save();

        return $this;
    }

    public function revert(): void
    {
        $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->removeAttribute(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER, self::ATTRIBUTE_CODE);
    }

    public static function getDependencies(): array
    {
        return [AddAccesswireAccountIdAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
