<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;

/**
 * Stock Magento keeps one cart per customer: customerCart and
 * createEmptyCart both return the customer's most recently updated active
 * cart. This creates another one outright. Being customer-owned from the
 * start, it's priced for the customer (their group) like customerCart is.
 *
 * Nothing else in core needs changing to use it - every cart query and
 * mutation, placeOrder included, accepts any active cart the token's
 * customer owns (QuoteGraphQl's GetCartForUser).
 */
class CreateCustomerCart implements ResolverInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Share $shareConfig,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly QuoteIdMaskResource $quoteIdMaskResource
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $store = $context->getExtensionAttributes()->getStore();
        $customer = $this->customerRepository->getById((int) $context->getUserId());

        // Customer accounts are per website here, so a customer can't hold a
        // cart in another website's store.
        if ($this->shareConfig->isWebsiteScope() && (int) $customer->getWebsiteId() !== (int) $store->getWebsiteId()) {
            throw new GraphQlAuthorizationException(__('The current customer can\'t use carts in this store.'));
        }

        $cart = $this->quoteFactory->create();
        $cart->setStoreId((int) $store->getId());
        $cart->setCustomer($customer);
        $cart->setCustomerIsGuest(0);
        $this->cartRepository->save($cart);

        // The `id` field resolves to the masked ID, which must exist first.
        $quoteIdMask = $this->quoteIdMaskFactory->create();
        $quoteIdMask->setQuoteId((int) $cart->getId());
        $this->quoteIdMaskResource->save($quoteIdMask);

        return ['cart' => ['model' => $cart]];
    }
}
