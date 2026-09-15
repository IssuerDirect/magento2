<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
use Magento\Customer\Model\CustomerRegistry;

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

// Defensive cleanup: drop any orphan stripe_customers rows left over from previous runs,
// and drop the Stripe customer on Stripe's side so each test starts with no saved payment
// methods. The rollback handles the normal case, but this keeps the fixture resilient to
// dirty state from earlier, unclean runs.
/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$stripeCustomersTable = $resource->getTableName('stripe_customers');

$existingStripeIds = $connection->fetchCol(
    $connection->select()
        ->from($stripeCustomersTable, 'stripe_id')
        ->where('customer_id = ?', 1)
);

if ($existingStripeIds) {
    /** @var \StripeIntegration\Payments\Model\Config $stripeConfig */
    $stripeConfig = $objectManager->get(\StripeIntegration\Payments\Model\Config::class);
    try {
        $stripeClient = $stripeConfig->getStripeClient();
    } catch (\Throwable $e) {
        $stripeClient = null;
    }

    if ($stripeClient) {
        foreach ($existingStripeIds as $stripeId) {
            try {
                $stripeClient->customers->delete($stripeId);
            } catch (\Throwable $e) {
                // Already deleted or unreachable; ignore.
            }
        }
    }

    $connection->delete($stripeCustomersTable, ['customer_id = ?' => 1]);
}

/** @var $repository \Magento\Customer\Api\CustomerRepositoryInterface */
$repository = $objectManager->create(\Magento\Customer\Api\CustomerRepositoryInterface::class);
$customer = $objectManager->create(\Magento\Customer\Model\Customer::class);
/** @var CustomerRegistry $customerRegistry */
$customerRegistry = $objectManager->get(CustomerRegistry::class);
/** @var Magento\Customer\Model\Customer $customer */
$customer->setWebsiteId(1)
    ->setId(1)
    ->setEmail('graphql@example.com')
    ->setPassword('password')
    ->setGroupId(1)
    ->setStoreId(1)
    ->setIsActive(1)
    ->setPrefix('Mr.')
    ->setFirstname('John')
    ->setMiddlename('A')
    ->setLastname('Smith')
    ->setSuffix('Esq.')
    ->setDefaultBilling(1)
    ->setDefaultShipping(1)
    ->setTaxvat('12')
    ->setGender(0);

$customer->isObjectNew(true);
$customer->save();
$customerRegistry->remove($customer->getId());
/** @var \Magento\JwtUserToken\Api\RevokedRepositoryInterface $revokedRepo */
$revokedRepo = $objectManager->get(\Magento\JwtUserToken\Api\RevokedRepositoryInterface::class);
$revokedRepo->saveRevoked(
    new \Magento\JwtUserToken\Api\Data\Revoked(
        \Magento\Authorization\Model\UserContextInterface::USER_TYPE_CUSTOMER,
        (int) $customer->getId(),
        time() - 3600 * 24
    )
);
