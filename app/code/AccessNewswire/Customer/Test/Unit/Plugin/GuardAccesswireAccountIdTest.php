<?php
declare(strict_types=1);

namespace AccessNewswire\Customer\Test\Unit\Plugin;

use AccessNewswire\Customer\Plugin\GuardAccesswireAccountId;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class GuardAccesswireAccountIdTest extends TestCase
{
    private const ATTRIBUTE_CODE = 'accesswire_account_id';
    private const CUSTOMER_ID = 42;

    private UserContextInterface&Stub $userContext;
    private State&Stub $appState;
    private CustomerRepositoryInterface&Stub $repository;
    private CustomerInterface&MockObject $customer;
    private GuardAccesswireAccountId $plugin;

    protected function setUp(): void
    {
        $this->userContext = $this->createStub(UserContextInterface::class);
        $this->appState = $this->createStub(State::class);
        $this->repository = $this->createStub(CustomerRepositoryInterface::class);
        $this->customer = $this->createMock(CustomerInterface::class);
        $this->plugin = new GuardAccesswireAccountId($this->userContext, $this->appState);
    }

    public static function trustedCallerProvider(): array
    {
        return [
            'admin area' => [Area::AREA_ADMINHTML, null],
            'cron' => [Area::AREA_CRONTAB, null],
            'REST with admin token' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_ADMIN],
            'REST with integration token' => [
                Area::AREA_WEBAPI_REST,
                UserContextInterface::USER_TYPE_INTEGRATION,
            ],
        ];
    }

    #[DataProvider('trustedCallerProvider')]
    public function testTrustedCallerCanSetValue(string $areaCode, ?int $userType): void
    {
        $this->appState->method('getAreaCode')->willReturn($areaCode);
        $this->userContext->method('getUserType')->willReturn($userType);

        $this->customer->expects($this->never())->method('setCustomAttribute');

        $this->assertSame(
            [$this->customer, 'hash'],
            $this->plugin->beforeSave($this->repository, $this->customer, 'hash')
        );
    }

    public function testCliCanSetValue(): void
    {
        $this->appState->method('getAreaCode')
            ->willThrowException(new LocalizedException(__('Area code is not set')));

        $this->customer->expects($this->never())->method('setCustomAttribute');

        $this->plugin->beforeSave($this->repository, $this->customer);
    }

    public static function untrustedCallerProvider(): array
    {
        return [
            'REST with customer token' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_CUSTOMER],
            'GraphQL with customer token' => [Area::AREA_GRAPHQL, UserContextInterface::USER_TYPE_CUSTOMER],
            'storefront session' => [Area::AREA_FRONTEND, UserContextInterface::USER_TYPE_CUSTOMER],
            'REST guest' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_GUEST],
            'REST without user context' => [Area::AREA_WEBAPI_REST, null],
        ];
    }

    #[DataProvider('untrustedCallerProvider')]
    public function testUntrustedCallerGetsStoredValueRestored(string $areaCode, ?int $userType): void
    {
        $this->givenCaller($areaCode, $userType);
        $this->customer->method('getId')->willReturn(self::CUSTOMER_ID);
        $this->givenStoredCustomer($this->storedAttribute('AW-1'));

        $this->customer->expects($this->once())
            ->method('setCustomAttribute')
            ->with(self::ATTRIBUTE_CODE, 'AW-1');

        $this->plugin->beforeSave($this->repository, $this->customer);
    }

    public function testUntrustedCallerCannotSetValueOnNewCustomer(): void
    {
        $this->givenCaller(Area::AREA_GRAPHQL, UserContextInterface::USER_TYPE_GUEST);
        $this->customer->method('getId')->willReturn(null);

        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->expects($this->never())->method('getById');
        $this->customer->expects($this->once())
            ->method('setCustomAttribute')
            ->with(self::ATTRIBUTE_CODE, null);

        $this->plugin->beforeSave($repository, $this->customer);
    }

    public function testUntrustedCallerCannotSetValueWhenNoneStored(): void
    {
        $this->givenCaller(Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_CUSTOMER);
        $this->customer->method('getId')->willReturn(self::CUSTOMER_ID);
        $this->givenStoredCustomer(null);

        $this->customer->expects($this->once())
            ->method('setCustomAttribute')
            ->with(self::ATTRIBUTE_CODE, null);

        $this->plugin->beforeSave($this->repository, $this->customer);
    }

    public function testUntrustedCallerCannotSetValueWhenStoredCustomerIsMissing(): void
    {
        $this->givenCaller(Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_CUSTOMER);
        $this->customer->method('getId')->willReturn(self::CUSTOMER_ID);
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException());

        $this->customer->expects($this->once())
            ->method('setCustomAttribute')
            ->with(self::ATTRIBUTE_CODE, null);

        $this->plugin->beforeSave($this->repository, $this->customer);
    }

    private function givenCaller(string $areaCode, ?int $userType): void
    {
        $this->appState->method('getAreaCode')->willReturn($areaCode);
        $this->userContext->method('getUserType')->willReturn($userType);
    }

    private function givenStoredCustomer(?AttributeInterface $attribute): void
    {
        $stored = $this->createStub(CustomerInterface::class);
        $stored->method('getCustomAttribute')->willReturnMap([[self::ATTRIBUTE_CODE, $attribute]]);
        $this->repository->method('getById')->willReturnMap([[self::CUSTOMER_ID, $stored]]);
    }

    private function storedAttribute(string $value): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn($value);
        return $attribute;
    }
}
