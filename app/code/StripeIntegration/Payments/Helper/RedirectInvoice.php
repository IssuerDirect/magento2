<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;

// Reverses the in-memory side effects of Magento's CaptureOperation::invoice()
// when we discover, after PaymentElement::confirm(), that the customer must be
// redirected externally and we don't want a STATE_OPEN invoice persisted on the
// order. The companion FilterRelatedObjects plugin keeps the de-registered
// invoice out of $order->getRelatedObjects() at save time.
class RedirectInvoice
{
    public const FLAG = 'stripe_deregistered';

    private $invoiceCapture;
    private $eventManager;
    private $suppressInvoiceRegisterEvent = false;

    public function __construct(
        \StripeIntegration\Payments\Model\Invoice\Capture $invoiceCapture,
        \Magento\Framework\Event\ManagerInterface $eventManager
    )
    {
        $this->invoiceCapture = $invoiceCapture;
        $this->eventManager = $eventManager;
    }

    // Returns the invoice currently in the capture path. At the time pay() is
    // running, AbstractOperation::invoice() has already called register() but
    // has not yet called addRelatedObject(), so we cannot find it via the
    // order's related objects. Instead we rely on the SalesOrderPaymentCapture
    // observer which stores the in-flight invoice in the Capture registry
    // before our method->capture() is called.
    public function findUnsavedInvoice(Order $order): ?Invoice
    {
        $invoice = $this->invoiceCapture->getInvoice();
        if ($invoice instanceof Invoice && !$invoice->getId())
        {
            return $invoice;
        }

        return null;
    }

    public function shouldSuppressInvoiceRegisterEvent(): bool
    {
        return $this->suppressInvoiceRegisterEvent;
    }

    public function clearSuppressInvoiceRegisterEvent(): void
    {
        $this->suppressInvoiceRegisterEvent = false;
    }

    // Reverses the order-level and order-item-level totals applied by
    // Invoice::register() and Invoice\Item::register() and tags the invoice
    // so the FilterRelatedObjects plugin filters it out at save time.
    public function deregister(Order $order, Invoice $invoice): void
    {
        if ($invoice->getDataByKey(self::FLAG))
        {
            return;
        }

        $this->suppressInvoiceRegisterEvent = true;

        $this->eventManager->dispatch('stripe_invoice_deregister_before', [
            'order' => $order,
            'invoice' => $invoice
        ]);

        foreach ($invoice->getAllItems() as $item)
        {
            $orderItem = $item->getOrderItem();
            if (!$orderItem)
            {
                continue;
            }

            $orderItem->setQtyInvoiced($orderItem->getQtyInvoiced() - $item->getQty());

            $orderItem->setTaxInvoiced($orderItem->getTaxInvoiced() - $item->getTaxAmount());
            $orderItem->setBaseTaxInvoiced($orderItem->getBaseTaxInvoiced() - $item->getBaseTaxAmount());

            $orderItem->setDiscountTaxCompensationInvoiced(
                $orderItem->getDiscountTaxCompensationInvoiced() - $item->getDiscountTaxCompensationAmount()
            );
            $orderItem->setBaseDiscountTaxCompensationInvoiced(
                $orderItem->getBaseDiscountTaxCompensationInvoiced() - $item->getBaseDiscountTaxCompensationAmount()
            );

            $orderItem->setDiscountInvoiced($orderItem->getDiscountInvoiced() - $item->getDiscountAmount());
            $orderItem->setBaseDiscountInvoiced($orderItem->getBaseDiscountInvoiced() - $item->getBaseDiscountAmount());

            $orderItem->setRowInvoiced($orderItem->getRowInvoiced() - $item->getRowTotal());
            $orderItem->setBaseRowInvoiced($orderItem->getBaseRowInvoiced() - $item->getBaseRowTotal());
        }

        $order->setTotalInvoiced($order->getTotalInvoiced() - $invoice->getGrandTotal());
        $order->setBaseTotalInvoiced($order->getBaseTotalInvoiced() - $invoice->getBaseGrandTotal());

        $order->setSubtotalInvoiced($order->getSubtotalInvoiced() - $invoice->getSubtotal());
        $order->setBaseSubtotalInvoiced($order->getBaseSubtotalInvoiced() - $invoice->getBaseSubtotal());

        $order->setTaxInvoiced($order->getTaxInvoiced() - $invoice->getTaxAmount());
        $order->setBaseTaxInvoiced($order->getBaseTaxInvoiced() - $invoice->getBaseTaxAmount());

        $order->setDiscountTaxCompensationInvoiced(
            $order->getDiscountTaxCompensationInvoiced() - $invoice->getDiscountTaxCompensationAmount()
        );
        $order->setBaseDiscountTaxCompensationInvoiced(
            $order->getBaseDiscountTaxCompensationInvoiced() - $invoice->getBaseDiscountTaxCompensationAmount()
        );

        $order->setShippingTaxInvoiced($order->getShippingTaxInvoiced() - $invoice->getShippingTaxAmount());
        $order->setBaseShippingTaxInvoiced($order->getBaseShippingTaxInvoiced() - $invoice->getBaseShippingTaxAmount());

        $order->setShippingInvoiced($order->getShippingInvoiced() - $invoice->getShippingAmount());
        $order->setBaseShippingInvoiced($order->getBaseShippingInvoiced() - $invoice->getBaseShippingAmount());

        $order->setDiscountInvoiced($order->getDiscountInvoiced() - $invoice->getDiscountAmount());
        $order->setBaseDiscountInvoiced($order->getBaseDiscountInvoiced() - $invoice->getBaseDiscountAmount());

        $order->setBaseTotalInvoicedCost($order->getBaseTotalInvoicedCost() - $invoice->getBaseCost());

        // Reverse GiftCardAccount observer side effects
        if ($invoice->getBaseGiftCardsAmount()) {
            $order->setBaseGiftCardsInvoiced(
                $order->getBaseGiftCardsInvoiced() - $invoice->getBaseGiftCardsAmount()
            );
            $order->setGiftCardsInvoiced(
                $order->getGiftCardsInvoiced() - $invoice->getGiftCardsAmount()
            );
        }

        // Reverse Reward observer side effects
        if ($invoice->getBaseRewardCurrencyAmount()) {
            $order->setRwrdCurrencyAmountInvoiced(
                $order->getRwrdCurrencyAmountInvoiced() - $invoice->getRewardCurrencyAmount()
            );
            $order->setBaseRwrdCrrncyAmtInvoiced(
                $order->getBaseRwrdCrrncyAmtInvoiced() - $invoice->getBaseRewardCurrencyAmount()
            );
        }

        $invoice->setData(self::FLAG, true);

        // Clear cached invoices for the test suite, then re-apply the order filter. clear()
        // resets the collection to an unloaded state with no WHERE clause; re-running
        // setOrderFilter() restores Magento's guard so a later getInvoiceCollection()->count()
        // either short-circuits (unsaved order: totalRecords = 0) or queries only this order's
        // invoices (saved order: order_id filter) instead of every invoice in the store.
        $order->getInvoiceCollection()->clear()->setOrderFilter($order);

        $this->eventManager->dispatch('stripe_invoice_deregister_after', [
            'order' => $order,
            'invoice' => $invoice
        ]);
    }
}
