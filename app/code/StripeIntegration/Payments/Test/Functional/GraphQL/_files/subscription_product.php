<?php
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\DataObjectHelper;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var ProductInterfaceFactory $productFactory */
$productFactory = $objectManager->get(ProductInterfaceFactory::class);
/** @var DataObjectHelper $dataObjectHelper */
$dataObjectHelper = $objectManager->get(DataObjectHelper::class);
/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->get(ProductRepositoryInterface::class);

try {
    $existing = $productRepository->get('virtual-monthly-subscription-product');
    $productId = $existing->getId();
} catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    $product = $productFactory->create();
    $productData = [
        ProductInterface::TYPE_ID => Type::TYPE_VIRTUAL,
        ProductInterface::ATTRIBUTE_SET_ID => 4,
        ProductInterface::SKU => 'virtual-monthly-subscription-product',
        ProductInterface::NAME => 'Virtual Monthly Subscription',
        ProductInterface::PRICE => 10,
        ProductInterface::VISIBILITY => Visibility::VISIBILITY_BOTH,
        ProductInterface::STATUS => Status::STATUS_ENABLED,
    ];
    $dataObjectHelper->populateWithArray($product, $productData, ProductInterface::class);
    $product
        ->setWebsiteIds([1])
        ->setStockData([
            'qty' => 100,
            'is_in_stock' => true,
            'manage_stock' => true,
        ]);
    $savedProduct = $productRepository->save($product);
    $productId = $savedProduct->getId();
}

/** @var \StripeIntegration\Payments\Model\SubscriptionOptionsFactory $subscriptionOptionsFactory */
$subscriptionOptionsFactory = $objectManager->get(\StripeIntegration\Payments\Model\SubscriptionOptionsFactory::class);
$subscriptionOptionsFactory->create()->setData([
    'product_id'        => $productId,
    'sub_enabled'       => 1,
    'sub_interval'      => 'month',
    'sub_interval_count' => 1,
    'sub_trial'         => 0,
    'sub_initial_fee'   => 0,
])->save();
