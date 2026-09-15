<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Integration\Model\Oauth\Token\RequestThrottler;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var \Magento\Framework\Registry $registry */
$registry = $objectManager->get(\Magento\Framework\Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var $customer \Magento\Customer\Model\Customer */
$customer = $objectManager->create(\Magento\Customer\Model\Customer::class);
$customer->load(1);
$customerId = (int) $customer->getId();

// Before deleting the Magento customer, clean up the associated Stripe customer record
// both remotely in Stripe and locally in the stripe_customers table. Otherwise, the next
// run of the test reuses the same Stripe customer and inherits its saved payment methods,
// which breaks tests that count them.
if ($customerId) {
    /** @var \Magento\Framework\App\ResourceConnection $resource */
    $resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
    $connection = $resource->getConnection();
    $tableName = $resource->getTableName('stripe_customers');

    $stripeCustomerIds = $connection->fetchCol(
        $connection->select()->from($tableName, 'stripe_id')->where('customer_id = ?', $customerId)
    );

    if ($stripeCustomerIds) {
        /** @var \StripeIntegration\Payments\Model\Config $config */
        $config = $objectManager->get(\StripeIntegration\Payments\Model\Config::class);

        try {
            $stripeClient = $config->getStripeClient();
        } catch (\Throwable $e) {
            $stripeClient = null;
        }

        if ($stripeClient) {
            foreach ($stripeCustomerIds as $stripeId) {
                try {
                    $stripeClient->customers->delete($stripeId);
                } catch (\Throwable $e) {
                    // Already deleted or unreachable; local row removal below still proceeds.
                }
            }
        }

        $connection->delete($tableName, ['customer_id = ?' => $customerId]);
    }

    $customer->delete();
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);

/* Unlock account if it was locked for tokens retrieval */
/** @var RequestThrottler $throttler */
$throttler = $objectManager->create(RequestThrottler::class);
$throttler->resetAuthenticationFailuresCount('graphql@example.com', RequestThrottler::USER_TYPE_CUSTOMER);
