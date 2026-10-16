<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Plugin;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Webapi\ParamOverriderCartId;

/**
 * REST `carts/mine` - used by the storefront checkout for every call it
 * makes for a logged-in customer (totals, payment information, placing the
 * order) - resolves to the customer's most recently updated active cart.
 * With several carts (createCustomerCart), that isn't necessarily the one
 * checkout was opened for, and it can change mid-checkout if another cart
 * is touched. Prefer the checkout session's cart when it's one of the
 * customer's active carts, as resumeCustomerCart sets it.
 *
 * Token-authenticated API clients have no checkout session cart, so they
 * keep the stock behaviour.
 */
class UseSessionCartForCustomer
{
    public function __construct(
        private readonly UserContextInterface $userContext,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    public function afterGetOverriddenValue(ParamOverriderCartId $subject, $result)
    {
        if ($this->userContext->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            return $result;
        }

        $sessionCartId = (int) $this->checkoutSession->getQuoteId();
        if ($sessionCartId === 0 || $sessionCartId === (int) $result) {
            return $result;
        }

        try {
            $cart = $this->cartRepository->getActive($sessionCartId);
        } catch (NoSuchEntityException $e) {
            return $result;
        }

        return (int) $cart->getCustomer()->getId() === (int) $this->userContext->getUserId()
            ? $cart->getId()
            : $result;
    }
}
