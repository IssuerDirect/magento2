<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Controller\Cart;

use AccessNewswire\AccesswireUi\Model\AllowedOrigins;
use AccessNewswire\AccesswireUi\Model\ReturnDestination;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Api\UserTokenValidatorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST checkout/cart/resumeCustomerCart
 *     token=<customer token>&cart_id=<masked id>&return_url=...&return_text=...
 *
 * The customer counterpart of resumeGuestCart: signs the token's customer
 * into the storefront and makes the given cart (one of theirs, e.g. from
 * createCustomerCart) the checkout session's cart. The token is POSTed so it
 * stays out of URLs, logs and history.
 *
 * Checkout's own REST calls (carts/mine/...) would still pick the customer's
 * most recently updated cart; see Plugin\UseSessionCartForCustomer.
 */
class ResumeCustomerCart implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly AllowedOrigins $allowedOrigins,
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly UserTokenValidatorInterface $tokenValidator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Share $shareConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly ReturnDestination $returnDestination
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create()->setPath('checkout/cart');

        try {
            $customer = $this->getTokenCustomer((string) $this->request->getParam('token'));
            $cart = $this->getCustomerCart((string) $this->request->getParam('cart_id'), $customer);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }

        if ((int) $this->customerSession->getCustomerId() !== (int) $customer->getId()) {
            if ($this->customerSession->isLoggedIn()) {
                $this->customerSession->logout();
            }
            // Logging in merges whatever cart the session holds into the
            // customer's most recently updated cart (Checkout's
            // loadCustomerQuote on customer_login) - with none, it doesn't.
            $this->checkoutSession->setQuoteId(null);
            $this->customerSession->setCustomerDataAsLoggedIn($customer);
        }

        // Checkout\Model\Session::getQuote keeps this as long as it's active
        // and owned by the logged-in customer.
        $this->checkoutSession->setQuoteId((int) $cart->getId());

        $this->returnDestination->set(
            (string) $this->request->getParam('return_url'),
            (string) $this->request->getParam('return_text')
        );

        return $redirect;
    }

    /**
     * A form POST from accesswire-ui can't carry Magento's form key; the
     * Origin check stands in for it, so another site can't sign a browser in
     * as a customer whose token it holds.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->allowedOrigins->isAllowed((string) $this->request->getHeader('Origin'));
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException(
            $this->redirectFactory->create()->setPath('checkout/cart'),
            [__('This checkout link can only be used from AccessWire.')]
        );
    }

    /**
     * @throws LocalizedException
     */
    private function getTokenCustomer(string $rawToken): CustomerInterface
    {
        try {
            $token = $this->tokenReader->read($rawToken);
            $this->tokenValidator->validate($token);
        } catch (UserTokenException | AuthorizationException $e) {
            throw new LocalizedException(__('Your session has expired. Please return to AccessWire and try again.'));
        }

        if ($token->getUserContext()->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            throw new LocalizedException(__('Your session has expired. Please return to AccessWire and try again.'));
        }

        $customer = $this->customerRepository->getById((int) $token->getUserContext()->getUserId());

        // Customer accounts are per website here.
        if ($this->shareConfig->isWebsiteScope()
            && (int) $customer->getWebsiteId() !== (int) $this->storeManager->getWebsite()->getId()
        ) {
            throw new LocalizedException(__('This account can\'t be used in this store.'));
        }

        return $customer;
    }

    /**
     * @throws LocalizedException
     */
    private function getCustomerCart(string $maskedCartId, CustomerInterface $customer): CartInterface
    {
        try {
            $cart = $this->cartRepository->getActive($this->maskedQuoteIdToQuoteId->execute($maskedCartId));
        } catch (NoSuchEntityException $e) {
            $cart = null;
        }

        if ($cart === null
            || (int) $cart->getCustomer()->getId() !== (int) $customer->getId()
            || (int) $this->storeManager->getStore((int) $cart->getStoreId())->getWebsiteId()
                !== (int) $this->storeManager->getWebsite()->getId()
        ) {
            throw new LocalizedException(__('That cart could not be found. Please return to AccessWire and try again.'));
        }

        return $cart;
    }
}
