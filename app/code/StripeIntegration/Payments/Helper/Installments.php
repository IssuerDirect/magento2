<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

use \Magento\Framework\Serialize\Serializer\Json;

class Installments
{
    private $currencyHelper;
    private $serializer;

    public function __construct(
        Currency $currencyHelper,
        Json $serializer
    ) {
        $this->currencyHelper = $currencyHelper;
        $this->serializer = $serializer;
    }
    public function getInstallmentsPlanDetails($installments, $order)
    {
        $details['details'][] = $this->getOnePaymentOption($order);
        foreach ($installments as $installment) {
            $component['data'] = $installment;
            $component['label'] = __('%1 payments of %2',
                $installment['count'],
                $this->currencyHelper->addCurrencySymbol($order->getGrandTotal() / $installment['count'], $order->getOrderCurrencyCode())
            );
            $details['details'][] = $component;
        }
        $details['total_label'] = __(
            'Total: %1',
            $this->currencyHelper->addCurrencySymbol($order->getGrandTotal(), $order->getOrderCurrencyCode())
        );

        return $details;
    }

    public function getOnePaymentOption($order)
    {
        return [
            'data' => ['type' => 'one_payment'],
            'label' => __('1 payment of %1', $this->currencyHelper->addCurrencySymbol($order->getGrandTotal()), $order->getOrderCurrencyCode()),
        ];
    }

    public function getInstallmentsDetailsFromPayment($installmentsPlan, $includeLabel = false)
    {
        $decodedInstallmentsPlan = $this->getDecodedInstallmentsPlan($installmentsPlan);

        if (!$this->isOnePayment($decodedInstallmentsPlan)) {
            $interval = $this->getInterval($decodedInstallmentsPlan['interval']);
            if ($includeLabel) {
                return __("Installments: %1 %2", $decodedInstallmentsPlan['count'], $interval);
            } else {
                return __("%1 %2", $decodedInstallmentsPlan['count'], $interval);
            }
        }

        return null;
    }

    /**
     * Have options in case there are installments which are not paid monthly.
     *
     * @param $interval
     * @return mixed|string
     */
    public function getInterval($interval)
    {
        switch ($interval) {
            case 'day':
                return __('days');
            case 'week':
                return __('weeks');
            case 'month':
                return __('months');
            case 'year':
                return __('years');
            default:
                return $interval;
        }
    }

    public function isOnePayment($installmentsPlan)
    {
        return $installmentsPlan['type'] === 'one_payment';
    }

    public function getDecodedInstallmentsPlan($installmentsPlan)
    {
        return $this->serializer->unserialize($installmentsPlan);
    }
}