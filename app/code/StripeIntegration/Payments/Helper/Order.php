<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order as MagentoOrder;
use StripeIntegration\Payments\Exception\OrderPlacementInProgressException;

class Order
{
    private const PLACEMENT_LOCK_PREFIX = 'stripe_order_';

    public $orderComments = [];
    private $ordersCache = [];
    private $orderTaxManagement;
    private $subscriptionProductFactory;
    private $orderRepository;
    private $orderResourceModel;
    private $paymentResourceModel;
    private $orderSender;
    private $logger;
    private $tokenHelper;
    private $orderCollectionFactory;
    private $discountHelper;
    private $searchCriteriaBuilder;
    private $sequenceManager;
    private $orderManagement;
    private $serializer;
    private $lockManager;
    private $isSubscriptionsEnabled;

    public function __construct(
        \Magento\SalesSequence\Model\Manager $sequenceManager,
        \Magento\Tax\Api\OrderTaxManagementInterface $orderTaxManagement,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel,
        \Magento\Sales\Model\ResourceModel\Order\Payment $paymentResourceModel,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Helper\Logger $logger,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Discount $discountHelper,
        \Magento\Framework\Serialize\Serializer\Json $serializer,
        \Magento\Framework\Lock\LockManagerInterface $lockManager,
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Store $storeHelper
    )
    {
        $this->sequenceManager = $sequenceManager;
        $this->orderTaxManagement = $orderTaxManagement;
        $this->orderRepository = $orderRepository;
        $this->orderResourceModel = $orderResourceModel;
        $this->paymentResourceModel = $paymentResourceModel;
        $this->orderSender = $orderSender;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->logger = $logger;
        $this->tokenHelper = $tokenHelper;
        $this->discountHelper = $discountHelper;
        $this->serializer = $serializer;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderManagement = $orderManagement;
        $this->lockManager = $lockManager;
        $this->isSubscriptionsEnabled = $configHelper->getConfigData("payment/stripe_payments_subscriptions/active", $storeHelper->getStoreId());
    }

    /**
     * Array
     * (
     *     [code] => US-CA-*-Rate 1
     *     [title] => US-CA-*-Rate 1
     *     [percent] => 8.2500
     *     [amount] => 1.65
     *     [base_amount] => 1.65
     * )
     */
    public function getAppliedTaxes($orderId)
    {
        $taxes = [];
        $appliedTaxes = $this->orderTaxManagement->getOrderTaxDetails($orderId)->getAppliedTaxes();

        foreach ($appliedTaxes as $appliedTax)
        {
            $taxes[] = $appliedTax->getData();
        }

        return $taxes;
    }

    public function orderAgeLessThan($minutes, $order)
    {
        $created = strtotime($order->getCreatedAt());
        $now = time();
        return (($now - $created) < ($minutes * 60));
    }

    public function hasSubscriptionsIn($orderItems)
    {
        if (!$this->isSubscriptionsEnabled)
        {
            return false;
        }

        foreach ($orderItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Description
     * @param array<\Magento\Sales\Model\Order\Item> $orderItems
     * @return bool
     */
    public function hasTrialSubscriptionsIn($orderItems)
    {
        foreach ($orderItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() && $subscriptionProductModel->hasTrialPeriod())
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve an order by its ID.
     *
     * @param int|string $orderId The entity ID of the order.
     * @return \Magento\Sales\Api\Data\OrderInterface The order instance.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If no order with the specified ID exists.
     */
    public function loadOrderById($orderId)
    {
        return $this->orderRepository->get($orderId);
    }

    public function saveOrder($order)
    {
        return $this->orderRepository->save($order);
    }

    /**
     * Acquire a lock keyed on the quote ID using Magento's lock manager.
     * Held by checkout for the full QuoteManagement::submit() (order place + email).
     *
     * @param int|string $quoteId
     * @param int $timeout Seconds to wait for the lock (0 = non-blocking)
     * @return bool True if the lock was acquired
     */
    public function acquireOrderPlacementLock($quoteId, int $timeout = 0): bool
    {
        if (empty($quoteId))
            return false;

        return $this->lockManager->lock($this->getOrderPlacementLockName($quoteId), $timeout);
    }

    /**
     * Release the lock keyed on the quote ID using Magento's lock manager.
     *
     * @param int|string $quoteId
     * @return void
     */
    public function releaseOrderPlacementLock($quoteId): void
    {
        if (empty($quoteId))
            return;

        $this->lockManager->unlock($this->getOrderPlacementLockName($quoteId));
    }

    /**
     * True while checkout still holds the placement lock for this quote.
     *
     * @param int|string $quoteId
     */
    public function isOrderPlacementLocked($quoteId): bool
    {
        if (empty($quoteId))
            return false;

        return $this->lockManager->isLocked($this->getOrderPlacementLockName($quoteId));
    }

    /**
     * Webhook-side barrier: if Magento is still inside quote submit (including the
     * order-email SubmitObserver), queue the event for processing right after checkout
     * releases the placement lock. Avoids full-saving a half-finished order over
     * concurrent column updates (send_email/email_sent).
     *
     * @param int|string|null $quoteId
     * @throws OrderPlacementInProgressException
     */
    public function ensureOrderPlacementComplete($quoteId): void
    {
        if (empty($quoteId))
            return;

        if ($this->isOrderPlacementLocked($quoteId))
        {
            throw new OrderPlacementInProgressException(
                "Order placement is still in progress for quote {$quoteId}.",
                202
            );
        }
    }

    private function getOrderPlacementLockName($quoteId): string
    {
        return self::PLACEMENT_LOCK_PREFIX . $quoteId;
    }

    /**
     * Save only specific fields on an order using direct SQL.
     * This bypasses the full save mechanism which can trigger inventory observers.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $fields Array of field names to save (e.g., ['stripe_payment_method_type', 'state', 'status'])
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function saveOrderFields($order, array $fields)
    {
        $connection = $this->orderResourceModel->getConnection();
        $tableName = $this->orderResourceModel->getMainTable();

        $updateData = [];
        foreach ($fields as $field) {
            if ($order->hasData($field)) {
                $updateData[$field] = $order->getData($field);
            }
        }

        if (!empty($updateData)) {
            $connection->update($tableName, $updateData, ['entity_id = ?' => $order->getId()]);
        }

        // Save any status history that was added
        foreach ($order->getStatusHistories() as $history) {
            if (!$history->getId()) {
                $history->setParentId($order->getId());
                $history->save();
            }
        }

        return $order;
    }

    public function loadOrderByIncrementId($incrementId, $useCache = true)
    {
        if (empty($incrementId))
            return null;

        if (!empty($this->ordersCache[$incrementId]) && $useCache)
            return $this->ordersCache[$incrementId];

        try
        {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->create();

            $orderList = $this->orderRepository->getList($searchCriteria);

            if ($orderList->getTotalCount() === 1)
            {
                $orders = $orderList->getItems();
                $order = reset($orders);
                return $this->ordersCache[$incrementId] = $order;
            }
        }
        catch(NoSuchEntityException $e)
        {
            return null;
        }
        catch (\Exception $e)
        {
            $this->logger->logError($e->getMessage(), $e->getTraceAsString());
            return null;
        }

        return null;
    }

    public function loadOrdersByQuoteId($quoteId)
    {
        if (empty($quoteId))
            return null;

        $orderCollection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('quote_id', $quoteId);

        return $orderCollection;
    }

    public function getOrderDescription($order)
    {
        if ($order->getCustomerIsGuest())
            $customerName = $order->getBillingAddress()->getName();
        else
            $customerName = $order->getCustomerName();

        if ($this->hasSubscriptionsIn($order->getAllItems()))
            $subscription = "subscription ";
        else
            $subscription = "";

        if ($this->isMultiShipping($order))
            $description = "Multi-shipping {$subscription}order #" . $order->getRealOrderId() . " by $customerName";
        else
            $description = "{$subscription}order #" . $order->getRealOrderId() . " by $customerName";

        return ucfirst($description);
    }

    public function getPaymentMethodId($order)
    {
        if (!$order || !$order->getPayment())
            return null;

        $payment = $order->getPayment();

        // // Confirmation token takes precedence to normal token
        // $confirmationTokenId = $payment->getAdditionalInformation("confirmation_token");

        // if ($confirmationTokenId)
        // {
        //     try
        //     {
        //         $confirmationToken = $this->config->getStripeClient()->confirmationTokens->retrieve($confirmationTokenId);
        //         if ($confirmationToken->payment_method)
        //         {
        //             return $confirmationToken->payment_method;
        //         }
        //     }
        //     catch (\Exception $e)
        //     {
        //         $this->helper->logError("Could not retrieve confirmation token: " . $e->getMessage());
        //     }
        // }

        $paymentMethodId = $payment->getAdditionalInformation("token");

        if ($this->tokenHelper->isPaymentMethodToken($paymentMethodId))
        {
            return $paymentMethodId;
        }

        return null;
    }

    public function isMultishipping($order)
    {
        if (!$order)
            return false;

        $shippingAddresses = $order->getShippingAddressesCollection();
        if ($shippingAddresses && count($shippingAddresses) > 1) {
            return true;
        }

        return false;
    }

    public function clearCache()
    {
        $this->ordersCache = [];
    }

    public function sendNewOrderEmailFor($order, $forceSend = false)
    {
        if (empty($order) || !$order->getId())
            return;

        if (!$order->getEmailSent() && $forceSend)
        {
            $order->setCanSendNewEmailFlag(true);
        }

        // Send the order email
        if ($order->getCanSendNewEmailFlag())
        {
            try
            {
                $this->orderSender->send($order);
                return true;
            }
            catch (\Exception $e)
            {
                $this->logger->logError($e->getMessage(), $e->getTraceAsString());
            }
        }

        return false;
    }

    public function addOrderComment($msg, $order, $isCustomerNotified = false)
    {
        if ($order)
            $order->addCommentToStatusHistory($msg);
    }

    public function holdOrder(&$order)
    {
        $order->setHoldBeforeState($order->getState());
        $order->setHoldBeforeStatus($order->getStatus());
        $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
            ->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_HOLDED));
        $comment = __("Order placed under manual review by Stripe Radar.");
        $order->addStatusToHistory(false, $comment, false);

        return $order;
    }

    public function getTransactionId($order)
    {
        if (!$order || !$order->getPayment())
            return null;

        $transactionId = $order->getPayment()->getLastTransId();
        $transactionId = $this->tokenHelper->cleanToken($transactionId);

        if (empty($transactionId))
            return null;

        return $transactionId;
    }

    public function getPaymentIntentId($order)
    {
        $transactionId = $this->getTransactionId($order);

        if (!$this->tokenHelper->isPaymentIntentToken($transactionId))
            return null;

        return $transactionId;
    }

    public function isSubscriptionWithoutPayment($order)
    {
        if (!$order || !$order->getPayment())
            return false;

        $payment = $order->getPayment();
        return $payment->getAdditionalInformation("is_trial_subscription_setup") ||
            $payment->getAdditionalInformation("is_future_subscription_setup");
    }

    public function getExpiringCoupon($order)
    {
        if (empty($order))
            return null;

        $discountRules = $this->discountHelper->getDiscountRules($order->getAppliedRuleIds());

        if (count($discountRules) > 1)
        {
            $this->logger->logError("Could not apply discount coupon: Multiple cart price rules were applied on the cart. Only one can be applied on subscription carts.");
            return null;
        }

        if (empty($discountRules))
        {
            return null;
        }

        $couponCode = $order->getCouponCode() ?? "rule_id_" . $discountRules[0]->getRuleId();
        $discountRules[0]->setCouponCode($couponCode);
        return $discountRules[0];
    }

    public function removeTransactions($order)
    {
        $order->getPayment()->setLastTransId(null);
        $order->getPayment()->setTransactionId(null);
        $order->getPayment()->save();
    }

    /**
     * Save only the additional_information field of a payment using direct SQL.
     * This avoids double-serialization that can occur when $payment->save() is called
     * while the payment model's additional_information is temporarily in a serialized state.
     */
    public function savePaymentAdditionalInformation($payment)
    {
        $additionalInformation = $payment->getAdditionalInformation();
        $connection = $this->paymentResourceModel->getConnection();
        $tableName = $this->paymentResourceModel->getMainTable();
        $connection->update(
            $tableName,
            ['additional_information' => $this->serializer->serialize($additionalInformation)],
            ['entity_id = ?' => $payment->getEntityId()]
        );
    }

    public function createInvoice($order, $transactionId = null)
    {
        $invoice = $order->prepareInvoice();
        $invoice->setTransactionId($transactionId);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::NOT_CAPTURE);
        $invoice->register();
        $order->addRelatedObject($invoice);

        $sequenceId = $this->sequenceManager->getSequence($invoice->getEntityType(), $order->getStoreId())->getNextValue();
        $invoice->setIncrementId($sequenceId);
        $invoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_OPEN);

        return $invoice;
    }

    public function saveHoldBeforeState($order): void
    {
        $heldStates = [
            Radar::MANUAL_REVIEW_STATE_CODE,
            Dispute::STRIPE_DISPUTE_STATE_CODE,
            MagentoOrder::STATE_HOLDED,
        ];

        if (in_array($order->getState(), $heldStates)) {
            return;
        }

        $order->setHoldBeforeState($order->getState());
        $order->setHoldBeforeStatus($order->getStatus());
    }

    public function restoreOrderState($order): bool
    {
        $heldStates = [
            Radar::MANUAL_REVIEW_STATE_CODE,
            Dispute::STRIPE_DISPUTE_STATE_CODE,
            MagentoOrder::STATE_HOLDED,
        ];

        if (!in_array($order->getState(), $heldStates)) {
            return false;
        }

        $state = $order->getHoldBeforeState();
        $status = $order->getHoldBeforeStatus();

        if (empty($state)) {
            $state = $this->resolveOrderState($order);
        }

        if (empty($status)) {
            $status = $order->getConfig()->getStateDefaultStatus($state);
        }

        $order->setState($state)->setStatus($status);
        return true;
    }

    private function resolveOrderState($order): string
    {
        if ($order->isCanceled()) {
            return MagentoOrder::STATE_CANCELED;
        }

        $totalRefunded = (float) $order->getTotalRefunded();
        if ($totalRefunded > 0 && $totalRefunded >= (float) $order->getGrandTotal()) {
            return MagentoOrder::STATE_CLOSED;
        }

        if ((float) $order->getTotalPaid() > 0) {
            return MagentoOrder::STATE_PROCESSING;
        }

        return MagentoOrder::STATE_PROCESSING;
    }

    public function setAsApproved($order, string $approvedBy): void
    {
        $comment = __('The payment has been approved by %1.', $approvedBy);

        $this->restoreOrderState($order);
        $order->addStatusToHistory($order->getStatus(), $comment);

        $this->saveOrder($order);
    }

    public function setAsRejected($order, string $rejectedBy, string $reasonCode): void
    {
        $comment = __("The payment has been rejected by %1 with reason: %2.", $rejectedBy, ucfirst(str_replace("_", " ", $reasonCode)));

        if ($reasonCode === "refunded_as_fraud")
        {
            $order->setState($order::STATE_PAYMENT_REVIEW);
            $order->addStatusToHistory($order::STATUS_FRAUD, $comment, $isCustomerNotified = false);
        }
        else
        {
            $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
        }

        $this->saveOrder($order);
    }

    public function getCancelledComment($order, $rejectedBy, $reasonCode = null)
    {
        $comment = __("The payment has been canceled by %1.", $rejectedBy);
        if ($reasonCode) {
            $comment = __("The payment has been canceled by %1 with reason: %2.", $rejectedBy, ucfirst(str_replace("_", " ", $reasonCode)));
        }

        return $comment;
    }

    public function cancel($order): bool
    {
        if (!$order->canCancel()) {
            return false;
        }

        // Using OrderManagementInterface to ensure proper event dispatch and observer execution
        // Note that this also saves the order after cancellation, so make sure to refresh the order after calling this method.
        return $this->orderManagement->cancel($order->getEntityId());
    }
}
