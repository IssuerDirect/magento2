<?php

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();

// Create a custom email template for subscription renewal orders
$template = $objectManager->create(\Magento\Email\Model\Template::class);
$template->setData([
    'template_code' => 'stripe_subscription_renewal_test',
    'template_text' => 'Stripe Renewal: Your subscription order {{var order.increment_id}} has been renewed.',
    'template_type' => \Magento\Email\Model\Template::TYPE_TEXT,
    'template_subject' => 'Subscription Renewal for Order {{var order.increment_id}}',
    'orig_template_code' => 'sales_email_order_template',
]);
$template->save();

// Set the config to use this custom template for subscription renewal orders
$configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);
$configResource->saveConfig(
    'payment/stripe_payments_subscriptions/order_email_template',
    $template->getId(),
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments_subscriptions/guest_order_email_template',
    $template->getId(),
    'stores',
    1
);

$objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
