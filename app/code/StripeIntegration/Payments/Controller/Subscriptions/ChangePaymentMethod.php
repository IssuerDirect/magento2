<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Controller\Subscriptions;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ActionInterface;

class ChangePaymentMethod implements ActionInterface
{
    private $helper;
    private $stripeCustomer;
    private $stripeSubscriptionFactory;
    private $customerSession;
    private $request;
    private $urlHelper;
    private $setupIntentFactory;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\App\RequestInterface $request,
        \StripeIntegration\Payments\Model\Stripe\SetupIntentFactory $setupIntentFactory
    )
    {
        $this->helper = $helper;
        $this->urlHelper = $urlHelper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->customerSession = $session;
        $this->request = $request;
        $this->setupIntentFactory = $setupIntentFactory;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn())
        {
            return $this->urlHelper->getControllerRedirect('customer/account/login');
        }

        $subscriptionId = $this->request->getParam('subscription_id');
        if (!$subscriptionId)
        {
            $this->helper->addError(__('Invalid subscription ID.'));
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }

        $setupIntentId = $this->request->getParam('setup_intent');
        if (!$setupIntentId)
        {
            $this->helper->addError(__('Invalid setup intent ID.'));
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }

        $setupIntentModel = $this->setupIntentFactory->create()->fromSetupIntentId($setupIntentId);
        $paymentMethodStripeObject = $setupIntentModel->getStripeObject();
        if (!$paymentMethodStripeObject || !$paymentMethodStripeObject->payment_method) {
            $this->helper->addError(__('Invalid setup intent ID.'));
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }

        try
        {
            if (!$this->stripeCustomer->getStripeId())
            {
                $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
                $this->helper->logError("Could not load customer account for subscription with ID $subscriptionId!");
            }
            else if (!$this->stripeCustomer->ownsSubscriptionId($subscriptionId))
            {
                $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
                $this->helper->logError("Customer does not own subscription with ID $subscriptionId!");
            }
            else
            {
                $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($subscriptionId);
                $stripeSubscriptionModel->update(['default_payment_method' => $paymentMethodStripeObject->payment_method]);
                $this->helper->addSuccess(__("The subscription has been updated."));
            }
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not change subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
            $this->helper->logError("Could not change subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
    }
}
