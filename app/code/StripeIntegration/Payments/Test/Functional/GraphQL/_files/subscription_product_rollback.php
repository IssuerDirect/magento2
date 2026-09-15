<?php

use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$registry = $objectManager->get(\Magento\Framework\Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

try {
    $productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
    $product = $productRepository->get('virtual-monthly-subscription-product');

    // Remove subscription option first
    $subscriptionOptionsCollection = $objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions\Collection::class);
    $subscriptionOptionsCollection->addFieldToFilter('product_id', $product->getId());
    foreach ($subscriptionOptionsCollection as $option) {
        $option->delete();
    }

    $productRepository->delete($product);
} catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    // Product does not exist, nothing to rollback
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
