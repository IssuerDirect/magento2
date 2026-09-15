<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\Transaction;

class Manager
{
    private $orderPaymentRepository;
    private $transactionRepository;

    public function __construct(
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
    ) {
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Added because the type of transaction which is searched for here is 'authorization', but we need
     * the 'order' type when bank transfers are involved.
     *
     * @param $subject
     * @param $result
     * @param $parentTransactionId
     * @param $paymentId
     * @param $orderId
     * @return bool|\Magento\Framework\Model\AbstractModel|mixed
     * @throws \Magento\Framework\Exception\InputException
     */
    public function afterGetAuthorizationTransaction(
        $subject,
        $result,
        $parentTransactionId,
        $paymentId,
        $orderId
    ) {
        if (!$result && $paymentId) {
            $methods = ['stripe_payments_bank_transfers', 'stripe_payments_invoice'];
            $payment = $this->orderPaymentRepository->get($paymentId);
            if ($payment && in_array($payment->getMethod(), $methods)) {
                return $this->transactionRepository->getByTransactionType(
                    'order',
                    $paymentId,
                    $orderId
                );
            }
        }

        return $result;
    }
}
