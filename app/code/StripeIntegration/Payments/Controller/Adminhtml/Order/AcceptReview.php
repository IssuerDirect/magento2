<?php

namespace StripeIntegration\Payments\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Sales\Api\OrderRepositoryInterface;
use StripeIntegration\Payments\Helper\Order as OrderHelper;
use StripeIntegration\Payments\Helper\Url;
use StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory;
use StripeIntegration\Payments\Model\Config;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\CacheInterface;

class AcceptReview extends Action
{
    private $orderRepository;
    private $urlHelper;
    private $stripePaymentIntentFactory;
    private $config;
    private $adminSession;
    private $cache;
    private $orderHelper;

    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository,
        Url $urlHelper,
        PaymentIntentFactory $stripePaymentIntentFactory,
        Config $config,
        Session $adminSession,
        CacheInterface $cache,
        OrderHelper $orderHelper
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->urlHelper = $urlHelper;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
        $this->config = $config;
        $this->adminSession = $adminSession;
        $this->cache = $cache;
        $this->orderHelper = $orderHelper;
    }
    public function execute()
    {
        $orderId = $this->getRequest()->getParam('order_id');

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Order ID is missing.'));
            return $this->urlHelper->getControllerRedirect('sales/order/index');
        }

        if (!$this->_authorization->isAllowed('StripeIntegration_Payments::accept_reject_manual_review')) {
            $this->messageManager->addErrorMessage(__('User cannot perform this action.'));
            return $this->urlHelper->getControllerRedirect('sales/order/view', ['order_id' => $orderId]);
        }

        $order = $this->orderRepository->get($orderId);
        $paymentIntentId = $order->getPayment()->getLastTransId();

        if (!$paymentIntentId) {
            $this->messageManager->addErrorMessage(__('Could not find Stripe Payment Intent ID for the order.'));
            return $this->urlHelper->getControllerRedirect('sales/order/index');
        }

        $paymentIntent = $this->stripePaymentIntentFactory->create()->fromPaymentIntentId($paymentIntentId, ['review']);
        $paymentIntentStripeObject = $paymentIntent->getStripeObject();
        $stipeClient = $this->config->getStripeClient();

        if (isset($paymentIntentStripeObject->review)) {
            try {
                if ($paymentIntentStripeObject->review->open) {
                    $closedReview = $stipeClient->reviews->approve($paymentIntentStripeObject->review->id);
                    // Added so that the review.closed webhook will not change anything when it is triggered
                    // if review was accepted from Magento admin.
                    $this->cache->save("1", "admin_reviewed_" . $closedReview->id, ["stripe_payments"], 60 * 60);

                    // Update payment metadata to see the user which accepted the manual review
                    $admin = $this->adminSession->getUser();
                    $metaData = ['Review Approved by' => $admin->getUsername()];
                    $paymentIntent->update(['metadata' => $metaData]);

                    $this->orderHelper->setAsApproved($order, __("admin user %1", $admin->getUsername()));
                    $this->orderHelper->saveOrder($order);
                } else {
                    $this->messageManager->addErrorMessage(__('Manual Review associated with the order is closed.'));
                }
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Could not accept the manual review: %1', $e->getMessage()));
            }
        } else {
            $this->messageManager->addErrorMessage(__('Manual Review associated with the order was not found.'));
        }

        return $this->urlHelper->getControllerRedirect('sales/order/view', ['order_id' => $orderId]);
    }
}