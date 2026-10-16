<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Controller\Cart;

use AccessNewswire\AccesswireUi\Model\ReturnDestination;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

/**
 * checkout/cart/resumeGuestCart?cart_id=...&return_url=...&return_text=...
 *
 * accesswire-ui's GraphQL guest cart (a masked cart id) and the storefront's
 * session-based checkout are disconnected in stock Magento. This adopts the
 * cart into the PHP session, remembers where to send the customer back to
 * after ordering, then redirects to the cart page.
 */
class ResumeGuestCart implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly ReturnDestination $returnDestination
    ) {
    }

    public function execute(): Redirect
    {
        $maskedCartId = (string) $this->request->getParam('cart_id');

        if ($maskedCartId !== '') {
            try {
                $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
                $this->checkoutSession->setQuoteId($quoteId);
            } catch (NoSuchEntityException $e) {
                // Unknown/expired cart id - proceed to the cart page as-is.
            }
        }

        $this->returnDestination->set(
            (string) $this->request->getParam('return_url'),
            (string) $this->request->getParam('return_text')
        );

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
