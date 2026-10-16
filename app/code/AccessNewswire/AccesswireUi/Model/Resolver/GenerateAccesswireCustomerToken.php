<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model\Resolver;

use AccessNewswire\Customer\Model\TrustedAccountIdWrite;
use AccessNewswire\Customer\Setup\Patch\Data\AddAccesswireAccountIdAttribute;
use AccessNewswire\Customer\Setup\Patch\Data\AddAccesswireAccountNameAttribute;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParametersFactory;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proof of concept, deliberately unauthenticated: issues a customer bearer
 * token for whichever AccessNewswire-website customer is linked to the given
 * AccessWire account ID (the `accesswire_account_id` attribute), creating
 * that customer from the supplied details (and address, if given) if there
 * isn't one yet. No
 * password involved - the same way the accessnewswire:customer:token
 * command works. Anyone who knows or guesses an account ID gets that
 * customer's token. Not for production.
 */
class GenerateAccesswireCustomerToken implements ResolverInterface
{
    private const WEBSITE_CODE = 'anw';
    private const CUSTOMER_GROUP_CODE = 'General';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly TrustedAccountIdWrite $trustedAccountIdWrite,
        private readonly UserTokenIssuerInterface $tokenIssuer,
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly UserTokenParametersFactory $tokenParametersFactory,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionFactory $regionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $accountId = trim((string) ($args['accesswire_account_id'] ?? ''));
        if ($accountId === '') {
            throw new GraphQlInputException(__('"accesswire_account_id" is required.'));
        }

        $accountName = trim((string) ($args['account_name'] ?? ''));

        $website = $this->storeManager->getWebsite(self::WEBSITE_CODE);
        $customer = $this->findCustomer($accountId, $website);
        if ($customer === null) {
            $customer = $this->createCustomer($accountId, $accountName, $website, $args);
            $this->addDefaultAddress($customer, $args['address'] ?? null);
        } elseif ($accountName !== '' && $this->getAccountName($customer) !== $accountName) {
            // Follows renames of the company in AccessWire.
            $customer->setCustomAttribute(AddAccesswireAccountNameAttribute::ATTRIBUTE_CODE, $accountName);
            $customer = $this->customerRepository->save($customer);
        }

        $token = $this->tokenIssuer->create(
            new CustomUserContext((int) $customer->getId(), UserContextInterface::USER_TYPE_CUSTOMER),
            $this->tokenParametersFactory->create()
        );
        $expires = $this->tokenReader->read($token)->getData()->getExpires();

        return [
            'token' => $token,
            'expires_at' => $expires->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findCustomer(string $accountId, WebsiteInterface $website): ?CustomerInterface
    {
        $customers = $this->customerRepository->getList(
            $this->searchCriteriaBuilder
                ->addFilter(AddAccesswireAccountIdAttribute::ATTRIBUTE_CODE, $accountId)
                ->addFilter('website_id', (int) $website->getId())
                ->create()
        )->getItems();

        // The attribute can't be unique at the database level, so guard here.
        if (count($customers) > 1) {
            throw new GraphQlInputException(
                __('More than one customer is linked to AccessWire account "%1".', $accountId)
            );
        }

        return $customers ? reset($customers) : null;
    }

    private function getAccountName(CustomerInterface $customer): ?string
    {
        return $customer->getCustomAttribute(AddAccesswireAccountNameAttribute::ATTRIBUTE_CODE)?->getValue();
    }

    private function createCustomer(
        string $accountId,
        string $accountName,
        WebsiteInterface $website,
        array $args
    ): CustomerInterface
    {
        $email = trim((string) ($args['email'] ?? ''));
        $firstname = trim((string) ($args['firstname'] ?? ''));
        $lastname = trim((string) ($args['lastname'] ?? ''));
        if ($email === '' || $firstname === '' || $lastname === '') {
            throw new GraphQlNoSuchEntityException(__(
                'No customer is linked to AccessWire account "%1", and "email", "firstname" and "lastname" are all needed to create one.',
                $accountId
            ));
        }

        $customer = $this->customerFactory->create()
            ->setEmail($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setWebsiteId((int) $website->getId())
            ->setStoreId((int) $website->getDefaultStore()->getId())
            ->setGroupId($this->getGroupId());
        $customer->setCustomAttribute(AddAccesswireAccountIdAttribute::ATTRIBUTE_CODE, $accountId);
        if ($accountName !== '') {
            $customer->setCustomAttribute(AddAccesswireAccountNameAttribute::ATTRIBUTE_CODE, $accountName);
        }

        try {
            return $this->trustedAccountIdWrite->execute(fn () => $this->customerRepository->save($customer));
        } catch (InputMismatchException) {
            // An existing customer with this email, not linked to the account.
            throw new GraphQlInputException(__(
                'A customer with email "%1" already exists but isn\'t linked to AccessWire account "%2".',
                $email,
                $accountId
            ));
        } catch (InputException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }
    }

    /**
     * Best effort: the address is a convenience for checkout, so one Magento
     * won't accept (e.g. a missing required field) is logged and skipped
     * rather than failing the sign-in.
     */
    private function addDefaultAddress(CustomerInterface $customer, ?array $input): void
    {
        if (!$input) {
            return;
        }

        $countryCode = strtoupper(trim((string) ($input['country_code'] ?? '')));
        $address = $this->addressFactory->create()
            ->setCustomerId((int) $customer->getId())
            ->setFirstname($customer->getFirstname())
            ->setLastname($customer->getLastname())
            ->setCompany(trim((string) ($input['company'] ?? '')) ?: null)
            ->setStreet(array_values(array_filter(array_map('trim', $input['street'] ?? []))))
            ->setCity(trim((string) ($input['city'] ?? '')))
            ->setPostcode(trim((string) ($input['postcode'] ?? '')))
            ->setCountryId($countryCode)
            ->setTelephone(trim((string) ($input['telephone'] ?? '')))
            ->setIsDefaultBilling(true)
            ->setIsDefaultShipping(true);

        // AccessWire sends the region as a code (e.g. "GA"); countries with
        // a region list in Magento need its ID.
        $regionCode = strtoupper(trim((string) ($input['region_code'] ?? '')));
        if ($regionCode !== '') {
            $region = $this->regionFactory->create()->loadByCode($regionCode, $countryCode);
            if ($region->getId()) {
                $address->setRegionId((int) $region->getId());
            }
        }

        try {
            $this->addressRepository->save($address);
        } catch (LocalizedException $e) {
            $this->logger->warning(sprintf(
                'AccesswireUi: could not add an address to new customer #%d: %s',
                $customer->getId(),
                $e->getMessage()
            ));
        }
    }

    private function getGroupId(): int
    {
        $groups = $this->groupRepository->getList(
            $this->searchCriteriaBuilder->addFilter('customer_group_code', self::CUSTOMER_GROUP_CODE)->create()
        )->getItems();
        if (!$groups) {
            throw new GraphQlInputException(__('Customer group "%1" doesn\'t exist.', self::CUSTOMER_GROUP_CODE));
        }

        return (int) reset($groups)->getId();
    }
}
