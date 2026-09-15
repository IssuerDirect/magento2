<?php

namespace StripeIntegration\Payments\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use StripeIntegration\Payments\Helper\Creditmemo;
use StripeIntegration\Payments\Helper\Order;
use StripeIntegration\Payments\Helper\Radar;
use StripeIntegration\Payments\Helper\Url;
use Magento\Backend\Model\Auth\Session;
use StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory;
use Magento\Framework\App\CacheInterface;

class RejectReview extends Action
{
    private $orderRepository;
    private $urlHelper;
    private $creditmemoHelper;
    private $adminSession;
    private $stripePaymentIntentFactory;
    private $cache;
    private $orderHelper;

    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository,
        Url $urlHelper,
        Creditmemo $creditmemoHelper,
        Session $adminSession,
        PaymentIntentFactory $stripePaymentIntentFactory,
        CacheInterface $cache,
        Order $orderHelper
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->urlHelper = $urlHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->adminSession = $adminSession;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
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

        if (isset($paymentIntentStripeObject->review) &&
            $paymentIntentStripeObject->review->open
        ) {
            if (count($order->getInvoiceCollection()) > 1) {
                $this->messageManager->addErrorMessage(__('Order has more than one invoice. Please refund the payment from Stripe Dashboard.'));
                return $this->urlHelper->getControllerRedirect('sales/order/view', ['order_id' => $orderId]);
            } else {
                try {
                    $this->orderHelper->restoreOrderState($order);
                    // Added so that the review.closed webhook will not change anything when it is triggered
                    // if the payment was refunded from Magento admin.
                    $this->cache->save("1", "admin_reviewed_" . $paymentIntentStripeObject->review->id, ["stripe_payments"], 60 * 60);
                    $reason = null;
                    if ($this->canCancelOrder($order)) {
                        $this->orderHelper->cancel($order);
                        if ($order->isCanceled()) {
                            $reason = __("Canceled via admin area");
                        }
                    } else {
                        $invoice = $order->getInvoiceCollection()->getFirstItem();
                        // Because there is no endpoint for rejecting a review, we will trigger an online creditmemo.
                        // This will automatically trigger a refund webhook and the rest will be handled by the webhooks
                        // in that suite.
                        $this->creditmemoHelper->createOnlineCreditmemoForInvoice($invoice, $order);
                        $reason = __("Rejected via admin area");
                    }

                    // If there is a reason set for the rejection, set the order as rejected
                    // Otherwise make sure the order is set to manual review status and add an appropriate comment
                    if ($reason) {
                        $admin = $this->adminSession->getUser();
                        $this->orderHelper->setAsRejected($order, __("admin user %1", $admin->getUsername()), $reason);
                    } else {
                        $comment = __("Order could not be rejected from the admin area. Please refund the payment from Stripe Dashboard.");
                        // Make sure the order is in the manual review state if it could not be cancelled/refunded
                        $order->setState(Radar::MANUAL_REVIEW_STATE_CODE)
                            ->setStatus(Radar::MANUAL_REVIEW_STATUS_CODE)
                            ->addStatusToHistory(Radar::MANUAL_REVIEW_STATUS_CODE, $comment, false);
                    }
                    $this->orderHelper->saveOrder($order);
                } catch (\Exception $e) {
                    $this->messageManager->addErrorMessage('An error occurred while rejecting the Manual Review for the payment.');
                }
            }
        }

        return $this->urlHelper->getControllerRedirect('sales/order/view', ['order_id' => $orderId]);
    }

    private function canCancelOrder($order)
    {
        if (count($order->getInvoiceCollection()) == 0) {
            return true;
        }

        $invoice = $order->getInvoiceCollection()->getFirstItem();
        if ($invoice->getState() == Invoice::STATE_OPEN) {
            return true;
        }

        return false;
    }
}