<?php

namespace StripeIntegration\Payments\Cron;

use StripeIntegration\Payments\Exception\SkipCaptureException;

class CaptureAuthorizations
{
    private $helper;
    private $multishippingHelper;
    private $multishippingQuoteCollection;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote\Collection $multishippingQuoteCollection
    ) {
        $this->helper = $helper;
        $this->multishippingHelper = $multishippingHelper;
        $this->multishippingQuoteCollection = $multishippingQuoteCollection;
    }

    public function execute()
    {
        $quoteModels = $this->multishippingQuoteCollection->getUncaptured();
        $transactionIds = [];

        foreach ($quoteModels as $quoteModel)
        {
            $paymentIntentId = $quoteModel->getPaymentIntentId();
            $transactionIds[$paymentIntentId] = $quoteModel;
        }

        foreach ($transactionIds as $paymentIntentId => $quoteModel)
        {
            $orders = $this->helper->getOrdersByTransactionId($paymentIntentId);
            if (empty($orders))
                continue;

            try
            {
                $this->multishippingHelper->captureOrdersFromCronJob($orders, $paymentIntentId);
                $quoteModel->setCaptured(true);
                $quoteModel->save();
            }
            catch (SkipCaptureException $e)
            {
                if ($e->getCode() == SkipCaptureException::ZERO_AMOUNT)
                {
                    // The orders were likely canceled
                }
                else
                {
                    $this->helper->logError($e->getMessage());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            }
        }
    }

}
