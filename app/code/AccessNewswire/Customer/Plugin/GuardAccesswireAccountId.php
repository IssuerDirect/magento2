<?php
declare(strict_types=1);

namespace AccessNewswire\Customer\Plugin;

use AccessNewswire\Customer\Setup\Patch\Data\AddAccesswireAccountIdAttribute;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Customer-facing APIs (REST /V1/customers/me, GraphQL updateCustomerV2 and
 * createCustomerV2) pass `custom_attributes` straight through to the
 * repository, so without this a customer could set their own AccessWire
 * account ID. Untrusted callers get the stored value put back instead.
 */
class GuardAccesswireAccountId
{
    private const TRUSTED_AREAS = [Area::AREA_ADMINHTML, Area::AREA_CRONTAB];

    private const TRUSTED_USER_TYPES = [
        UserContextInterface::USER_TYPE_ADMIN,
        UserContextInterface::USER_TYPE_INTEGRATION,
    ];

    public function __construct(
        private readonly UserContextInterface $userContext,
        private readonly State $appState
    ) {
    }

    public function beforeSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer,
        $passwordHash = null
    ): array {
        if (!$this->isTrustedCaller()) {
            $customer->setCustomAttribute(
                AddAccesswireAccountIdAttribute::ATTRIBUTE_CODE,
                $this->getStoredValue($subject, $customer)
            );
        }

        return [$customer, $passwordHash];
    }

    private function isTrustedCaller(): bool
    {
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            // No area is set for bin/magento commands.
            return true;
        }

        return in_array($areaCode, self::TRUSTED_AREAS, true)
            || in_array($this->userContext->getUserType(), self::TRUSTED_USER_TYPES, true);
    }

    private function getStoredValue(CustomerRepositoryInterface $subject, CustomerInterface $customer): ?string
    {
        if (!$customer->getId()) {
            return null;
        }

        try {
            $stored = $subject->getById($customer->getId())
                ->getCustomAttribute(AddAccesswireAccountIdAttribute::ATTRIBUTE_CODE);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $stored?->getValue();
    }
}
