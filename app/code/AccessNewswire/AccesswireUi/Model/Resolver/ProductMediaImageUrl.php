<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model\Resolver;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves a `ProductInterface` field to the full URL of the image assigned
 * to the image role (media_image attribute) of the same name - or null when
 * none is, unlike core's ProductImage, which falls back to a placeholder.
 * The field name doubling as the attribute code also gets the attribute
 * loaded onto the product (CatalogGraphQl selects requested fields).
 */
class ProductMediaImageUrl implements ResolverInterface
{
    private const NO_SELECTION = 'no_selection';

    public function __construct(
        private readonly MediaConfig $mediaConfig
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $product = $value['model'] ?? null;
        if (!$product instanceof ProductInterface) {
            return null;
        }

        $file = (string) $product->getData($field->getName());
        if ($file === '' || $file === self::NO_SELECTION) {
            return null;
        }

        return $this->mediaConfig->getMediaUrl($file);
    }
}
