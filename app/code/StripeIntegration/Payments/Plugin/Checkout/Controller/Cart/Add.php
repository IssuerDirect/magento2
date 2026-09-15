<?php

namespace StripeIntegration\Payments\Plugin\Checkout\Controller\Cart;

use Magento\Checkout\Model\Session;
use Magento\Framework\Message\ManagerInterface;
use StripeIntegration\Payments\Helper\Subscriptions;
use StripeIntegration\Payments\Helper\Url;

class Add
{
    private $subscriptionsHelper;
    private $checkoutSession;
    private $urlHelper;
    private $messageManager;

    public function __construct(
        Subscriptions $subscriptionsHelper,
        Session $checkoutSession,
        Url $urlHelper,
        ManagerInterface $messageManager
    ) {
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->checkoutSession = $checkoutSession;
        $this->urlHelper = $urlHelper;
        $this->messageManager = $messageManager;
    }

    public function afterExecute(
        \Magento\Checkout\Controller\Cart\Add $subject,
        $result
    ) {
        if ($this->checkoutSession->getCancelSubscriptionUpdate()) {
            $this->messageManager->getMessages(true);
            $this->subscriptionsHelper->cancelSubscriptionUpdate();
            $this->checkoutSession->unsCancelSubscriptionUpdate();

            $redirectUrl = $this->urlHelper->getUrl('stripe/customer/subscriptions');

            if ($subject->getRequest()->isAjax()) {
                $subject->getResponse()->representJson(json_encode(['backUrl' => $redirectUrl]));
                return $subject->getResponse();
            }

            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }

        return $result;
    }
}
