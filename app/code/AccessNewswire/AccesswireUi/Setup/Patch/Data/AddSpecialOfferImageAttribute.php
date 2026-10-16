<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Frontend\Image as ImageFrontendModel;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * An image role ("Special Offer Image"), like Base/Small/Thumbnail: assigned
 * to one of the product's gallery images under Images & Videos. Shown by
 * accesswire-ui's "Today's special offer" widget.
 */
class AddSpecialOfferImageAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTRIBUTE_CODE = 'accesswire_special_offer_image';

    // The "Images" section of the product form. Looked up by code: passing
    // the name as `group` to addAttribute would try to create it anew.
    private const IMAGES_GROUP_CODE = 'image-management';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Same settings as the core image roles (CategorySetup's `image`).
        $eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'varchar',
                'label' => 'Special Offer Image',
                'input' => 'media_image',
                'frontend' => ImageFrontendModel::class,
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'used_in_product_listing' => true,
                'user_defined' => true,
            ]
        );

        // Image roles are offered for the media_image attributes in the
        // product's attribute set - so add it to every set's Images group.
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        foreach ($eavSetup->getAllAttributeSetIds($entityTypeId) as $attributeSetId) {
            $groupId = $eavSetup->getAttributeGroupByCode(
                $entityTypeId,
                $attributeSetId,
                self::IMAGES_GROUP_CODE,
                'attribute_group_id'
            ) ?: $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
            $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $groupId, self::ATTRIBUTE_CODE);
        }

        return $this;
    }

    public function revert(): void
    {
        $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->removeAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
