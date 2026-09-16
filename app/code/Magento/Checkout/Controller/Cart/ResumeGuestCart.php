<?php
declare(strict_types=1);

namespace Magento\Checkout\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

/**
 * Bridges a guest cart created via the GraphQL/REST cart APIs (identified only by its masked
 * quote id) into the traditional session-based cart the storefront's own /checkout/cart and
 * /checkout pages read from - these are two disconnected mechanisms in stock Magento: the
 * GraphQL guest cart APIs never touch Checkout\Model\Session, and Session::getQuote() has no
 * lookup path from a masked quote id (only from its own stored session quote id, or the active
 * quote of a logged-in customer). This is a stopgap so a headless-cart frontend (accesswire-ui)
 * can still hand off to the real storefront checkout; longer term the plan is to build checkout
 * natively against the GraphQL cart mutations instead and retire this bridge.
 *
 * Usage: /checkout/cart/resumeGuestCart?cart_id=<masked id>
 *
 * @api
 */
class ResumeGuestCart extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) {
        parent::__construct($context);
    }

    /**
     * Resolve the masked cart id from the request and adopt it as the current session quote,
     * then redirect to the cart page. Falls through to a plain cart-page redirect (i.e.
     * whatever's already in session, likely empty) if cart_id is missing or invalid, rather
     * than erroring - this is a convenience hand-off, not something a guest should get stuck on.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $maskedCartId = (string)$this->getRequest()->getParam('cart_id');

        if ($maskedCartId !== '') {
            try {
                $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
                $this->checkoutSession->setQuoteId($quoteId);
            } catch (NoSuchEntityException $e) {
                // Unknown/expired cart id - proceed to the cart page as-is.
            }
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/cart');
        return $resultRedirect;
    }
}
