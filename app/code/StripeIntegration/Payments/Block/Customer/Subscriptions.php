<?php

namespace StripeIntegration\Payments\Block\Customer;

class Subscriptions extends \Magento\Framework\View\Element\Template
{
    private $helper;
    private $subscriptionsHelper;
    private $subscriptionModels = [];
    private $activeSubscriptions;
    private $canceledSubscriptions;
    private static $allSubscriptions;
    private $paymentMethodHelper;
    private $stripeCustomer;
    private $subscriptionFactory;
    private $canceledSubscriptionsHtml;
    private $subscriptionCollectionFactory;
    private $productHelper;
    private $initParamsHelper;
    private $serializer;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription\CollectionFactory $subscriptionCollectionFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParamsHelper,
        array $data = []
    ) {
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionCollectionFactory = $subscriptionCollectionFactory;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->helper = $helper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->productHelper = $productHelper;
        $this->serializer = $serializer;
        $this->initParamsHelper = $initParamsHelper;

        parent::__construct($context, $data);
    }

    protected function getAllSubscriptions()
    {
        try
        {
            if (!empty(self::$allSubscriptions))
            {
                return self::$allSubscriptions;
            }

            $subscriptions = $this->stripeCustomer->getAllSubscriptions();

            foreach ($subscriptions as &$subscription)
            {
                foreach ($subscription->items->data as &$item)
                {
                    if (!empty($item->price->product) && is_string($item->price->product) &&
                        !empty($subscription->plan->product) && !is_string($subscription->plan->product))
                        $item->price->product = $subscription->plan->product;
                }
            }

            return self::$allSubscriptions = $subscriptions;
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
            return [];
        }
    }

    public function getActiveSubscriptions()
    {
        try
        {
            if (isset($this->activeSubscriptions))
            {
                return $this->activeSubscriptions;
            }

            $allSubscriptions = $this->getAllSubscriptions();
            $activeSubscriptions = [];

            foreach ($allSubscriptions as $subscription)
            {
                if (in_array($subscription->status, ['canceled', 'incomplete', 'incomplete_expired']))
                    continue;

                $activeSubscriptions[$subscription->id] = $subscription;
            }

            return $this->activeSubscriptions = $activeSubscriptions;
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
            return [];
        }
    }

    public function getCanceledSubscriptions()
    {
        try
        {
            if (isset($this->canceledSubscriptions))
            {
                return $this->canceledSubscriptions;
            }

            $allSubscriptions = $this->getAllSubscriptions();
            $canceledSubscriptions = [];
            $reactivatedSubscriptions = $this->subscriptionCollectionFactory->create()->getBySubscriptionStatus('reactivated');

            foreach ($allSubscriptions as $subscription)
            {
                if ($subscription->status != 'canceled')
                    continue;

                if (!in_array($subscription->id, $reactivatedSubscriptions) && $this->checkProductIsSalable($subscription))
                {
                    $canceledSubscriptions[$subscription->id] = $subscription;

                    if (count($canceledSubscriptions) >= 3) {
                        break;
                    }
                }
            }

            return $this->canceledSubscriptions = $canceledSubscriptions;
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
            return [];
        }
    }

    public function getSubscriptionDefaultPaymentMethod($sub)
    {
        if (!empty($sub->default_payment_method))
        {
            $methods = [
                $sub->default_payment_method->type => [
                    $sub->default_payment_method
                ]
            ];
            $formattedMethods = $this->paymentMethodHelper->formatPaymentMethods($methods);
            return array_pop($formattedMethods);
        }

        return null;
    }

    public function getCanceledSubscriptionsHtml()
    {
        if (isset($this->canceledSubscriptionsHtml))
            return $this->canceledSubscriptionsHtml;

        return $this->canceledSubscriptionsHtml = $this->getLayout()
            ->createBlock(\StripeIntegration\Payments\Block\Customer\Subscriptions::class)
            ->setTemplate('customer/canceled_subscriptions.phtml')
            ->toHtml();
    }

    public function getSubscriptionName($sub)
    {
        return $this->subscriptionsHelper->generateSubscriptionName($sub);
    }

    public function getSubscriptionModel(\Stripe\Subscription $subscription): ?\StripeIntegration\Payments\Model\Stripe\Subscription
    {
        if (isset($this->subscriptionModels[$subscription->id]))
            return $this->subscriptionModels[$subscription->id];

        try
        {
            $subscriptionModel = $this->subscriptionFactory->create()->fromSubscription($subscription);
            $this->subscriptionModels[$subscription->id] = $subscriptionModel;
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Could not load subscription model for subscription {$subscription->id}: " . $e->getMessage());
            $this->subscriptionModels[$subscription->id] = null;
        }

        return $this->subscriptionModels[$subscription->id];
    }

    public function getInitParams()
    {
        try
        {
            return $this->initParamsHelper->getNewSubscriptionPaymentMethodParams();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize([]);
        }
    }

    protected function checkProductIsSalable($subscription)
    {
        $productIDs = [];

        if (isset($subscription->metadata->{"Product ID"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"Product ID"});
        }
        else if (isset($subscription->metadata->{"SubscriptionProductIDs"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"SubscriptionProductIDs"});
        }

        if (empty($productIDs))
        {
            return false;
        }

        foreach ($productIDs as $productId)
        {
            try
            {
                $product = $this->productHelper->getProduct($productId);
                return $product->getIsSalable();
            }
            catch (\Exception $e)
            {
                return false;
            }
        }
    }
}
