<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Payment;

use StripeIntegration\Payments\Exception\Exception;

class LineItems
{
    private $order = null;
    private $invoice = null;
    private array $lineItems = [];

    private $helper;
    private $configHelper;
    private $invoiceItemHelper;
    private $loggerHelper;
    private $hasLineItemTaxes = false;
    private $hasLineItemDiscounts = false;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoiceItem $invoiceItemHelper
    )
    {
        $this->helper = $helper;
        $this->invoiceItemHelper = $invoiceItemHelper;
        $this->loggerHelper = $loggerHelper;
        $this->configHelper = $configHelper;
    }

    public function fromOrder($order)
    {
        try
        {
            $this->order = $order;
            $this->invoice = null;
            $this->addLineItems();
        }
        catch (\Exception $e)
        {
            $this->order = null;
            $this->invoice = null;
            $this->loggerHelper->logError("LineItems generation error for order " . $order->getIncrementId() . ": " . $e->getMessage());
        }

        return $this;
    }

    public function fromInvoice($invoice)
    {
        try
        {
            $this->order = $invoice->getOrder();
            $this->invoice = $invoice;
            $this->addLineItems();
        }
        catch (\Exception $e)
        {
            $this->order = null;
            $this->invoice = null;
            $this->loggerHelper->logError("LineItems generation error for invoice " . $invoice->getIncrementId() . ": " . $e->getMessage());
        }

        return $this;
    }

    public function addItem(
        string $productCode,
        string $productName,
        int $unitCost,
        int $quantity,
        string $unitOfMeasure,
        ?int $taxAmount,
        ?int $discountAmount,
        array $paymentMethodOptions
    ): void
    {
        $lineItem = [
            'product_code' => $productCode,
            'product_name' => $productName,
            'unit_cost' => $unitCost,
            'quantity' => $quantity,
            'unit_of_measure' => $unitOfMeasure
        ];

        if (is_numeric($taxAmount))
        {
            $lineItem['tax']['total_tax_amount'] = $taxAmount;
            $this->hasLineItemTaxes = true;
        }

        if (is_numeric($discountAmount) && $discountAmount > 0)
        {
            $lineItem['discount_amount'] = $discountAmount;
            $this->hasLineItemDiscounts = true;
        }

        if (!empty($paymentMethodOptions))
        {
            $lineItem['payment_method_options'] = $paymentMethodOptions;
        }

        $this->lineItems[] = $lineItem;
    }

    private function addLineItems(): void
    {
        $order = $this->order;
        $this->hasLineItemTaxes = false;
        $this->hasLineItemDiscounts = false;

        // Use invoice items if working with an invoice, otherwise use order items
        $items = $this->invoice ? $this->invoice->getAllItems() : $order->getAllItems();

        foreach ($items as $item)
        {
            // Get the order item (either directly or from invoice item)
            $orderItem = $this->invoice ? $item->getOrderItem() : $item;

            if (!$this->invoiceItemHelper->shouldIncludeOnInvoice($orderItem))
            {
                continue;
            }

            // Get product details
            $productCode = $this->getProductCode($orderItem);
            $productName = $this->getProductName($orderItem);
            $unitCost = $this->getUnitCost($orderItem);
            $quantity = $this->invoice ? (int) $item->getQty() : (int) $orderItem->getQtyOrdered();
            $taxAmount = $this->invoice ? $this->getTaxAmountFromInvoiceItem($item) : $this->getTaxAmount($orderItem);
            $discountAmount = $this->invoice ? $this->getDiscountAmountFromInvoiceItem($item) : $this->getDiscountAmount($orderItem);
            $unitOfMeasure = $this->getUnitOfMeasure($orderItem);
            $paymentMethodOptions = $this->getPaymentMethodOptions($orderItem);

            $this->addItem(
                $productCode,
                $productName,
                $unitCost,
                $quantity,
                $unitOfMeasure,
                $taxAmount,
                $discountAmount,
                $paymentMethodOptions
            );
        }
    }

    public function getProductCode($orderItem): string
    {
        $sku = $orderItem->getSku();

        if (!empty($sku) && strlen($sku) > 12)
        {
            return $orderItem->getProductId();
        }

        return $sku;
    }

    public function getTaxAmount($orderItem): ?int
    {
        $order = $this->order;

        if (is_numeric($orderItem->getTaxAmount()) && $orderItem->getTaxAmount() > 0)
        {
            return $this->helper->convertMagentoAmountToStripeAmount(
                $orderItem->getTaxAmount(),
                $order->getOrderCurrencyCode()
            );
        }

        return null;
    }

    public function getDiscountAmount($orderItem): int
    {
        $order = $this->order;

        if (is_numeric($orderItem->getDiscountAmount()))
        {
            return abs($this->helper->convertMagentoAmountToStripeAmount(
                $orderItem->getDiscountAmount(),
                $order->getOrderCurrencyCode()
            ));
        }

        return 0;
    }

    public function getTaxAmountFromInvoiceItem($invoiceItem): ?int
    {
        $order = $this->order;

        if (is_numeric($invoiceItem->getTaxAmount()) && $invoiceItem->getTaxAmount() > 0)
        {
            return $this->helper->convertMagentoAmountToStripeAmount(
                $invoiceItem->getTaxAmount(),
                $order->getOrderCurrencyCode()
            );
        }

        return null;
    }

    public function getDiscountAmountFromInvoiceItem($invoiceItem): int
    {
        $order = $this->order;

        if (is_numeric($invoiceItem->getDiscountAmount()))
        {
            return abs($this->helper->convertMagentoAmountToStripeAmount(
                $invoiceItem->getDiscountAmount(),
                $order->getOrderCurrencyCode()
            ));
        }

        return 0;
    }

    // Unit cost is always tax exclusive. Taxes are added separately.
    public function getUnitCost($orderItem): int
    {
        $order = $this->order;

        return $this->helper->convertMagentoAmountToStripeAmount(
            $orderItem->getPrice(),
            $order->getOrderCurrencyCode()
        );
    }

    /**
     * More codes at https://service.unece.org/trade/uncefact/vocabulary/rec20/
     *
     * | Unit of Measure | Description                              |
     * | --------------- | ---------------------------------------- |
     * | `EA`            | Each (individual item)                   |
     * | `BOX`           | Box (grouped items in a box)             |
     * | `PKG`           | Package                                  |
     * | `HUR`           | Hour (for labor or time-based services)  |
     * | `DAY`           | Day (for rentals or lodging)             |
     * | `LB`            | Pound (weight-based measure)             |
     * | `KG`            | Kilogram                                 |
     * | `L`             | Liter                                    |
     * | `GAL`           | Gallon                                   |
     * | `FT`            | Foot (length)                            |
     * | `IN`            | Inch                                     |
     * | `M`             | Meter                                    |
     * | `SQFT`          | Square Foot (area-based)                 |
     * | `C62`           | Piece (ISO standard for countable items) |
     * | `SET`           | Set (bundle of items)                    |
     * | `SERV`          | Service (intangible unit of work)        |
     * | --------------- | ---------------------------------------- |
     */
    public function getUnitOfMeasure($orderItem): string
    {
        $product = $orderItem->getProduct();
        if ($product && $product->getData('unit_of_measure'))
        {
            return substr((string)$product->getData('unit_of_measure'), 0, 12);
        }

        return 'EA';
    }

    // See https://docs.stripe.com/payments/payment-line-items#additional-klarna-supported-fields
    public function getPaymentMethodOptions($orderItem): array
    {
        $options = [];

        return $options;
    }

    public function getProductName($orderItem): string
    {
        $name = $orderItem->getName();

        if ($orderItem->getParentItem() && $orderItem->getParentItem()->getProductType() == "bundle")
        {
            $name = $orderItem->getParentItem()->getName() . " - " . $name;
        }
        else if ($orderItem->getProductType() == "configurable")
        {
            $selections = [];
            $attributes = $orderItem->getProductOptionByCode('attributes_info');
            if ($attributes)
            {
                foreach ($attributes as $attribute)
                {
                    if (isset($attribute['value']))
                    {
                        $selections[] = $attribute['value'];
                    }
                }
            }

            if (count($selections) > 0)
            {
                $name = $name . " - " . implode(", ", $selections);
            }
        }

        // Truncate to Stripe's limit (1024 chars, but different payment methods have different limits)
        return substr($name, 0, 1024);
    }

    public function getShippingDetails(): array
    {
        $order = $this->order;

        if ($order->getIsVirtual())
        {
            return [];
        }

        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress)
        {
            return [];
        }

        // Use invoice shipping amount if working with an invoice, otherwise use order shipping amount
        if ($this->invoice)
        {
            $shippingAmount = $this->hasLineItemTaxes ? $this->invoice->getShippingInclTax() : $this->invoice->getShippingAmount();
        }
        else
        {
            $shippingAmount = $this->hasLineItemTaxes ? $order->getShippingInclTax() : $order->getShippingAmount();
        }

        $shippingAmount = $this->helper->convertMagentoAmountToStripeAmount($shippingAmount, $order->getOrderCurrencyCode());

        return [
            'amount' => $shippingAmount,
            'from_postal_code' => $this->getFromPostalCode(),
            'to_postal_code' => $this->sanitizePostalCode($shippingAddress->getPostcode())
        ];
    }

    public function getFromPostalCode(): string
    {
        // Get store's postal code or use a default
        $store = $this->order->getStore();
        $storePostcode = $this->configHelper->getConfigData('shipping/origin/postcode', $store);
        return $this->sanitizePostalCode($storePostcode ?: '00000');
    }

    private function sanitizePostalCode($postcode): string
    {
        // Stripe allows max 10 chars, alphanumeric and hyphens
        $sanitized = preg_replace('/[^a-zA-Z0-9-]/', '', (string)$postcode);
        return substr($sanitized, 0, 10);
    }

    public function getPaymentIntentFormat(): array
    {
        $data = [
            'amount_details' => $this->getAmountDetails(),
            'payment_details' => $this->getPaymentDetails()
        ];

        if (!$this->isGrandTotalEqualToLineItemsTotal($data))
        {
            $context = $this->invoice ? "invoice " . $this->invoice->getIncrementId() : "order " . $this->order->getIncrementId();
            $this->loggerHelper->logError("LineItems total does not match grand total for " . $context . ". Not sending line items to Stripe.");
            return [];
        }

        return $data;
    }

    public function getOrderReference(): string
    {
        return $this->order->getIncrementId();
    }

    public function getTotalTaxAmount(): int
    {
        $order = $this->order;
        $totalTaxAmount = 0;

        // Use invoice tax amount if working with an invoice, otherwise use order tax amount
        $taxAmount = $this->invoice ? $this->invoice->getTaxAmount() : $order->getTaxAmount();

        // Calculate total tax amount
        if (is_numeric($taxAmount) && $taxAmount > 0)
        {
            $totalTaxAmount = $this->helper->convertMagentoAmountToStripeAmount(
                $taxAmount,
                $order->getOrderCurrencyCode()
            );
        }

        return $totalTaxAmount;
    }

    public function getTotalDiscountAmount(): ?int
    {
        $order = $this->order;
        $totalDiscountAmount = null;

        // Use invoice discount amount if working with an invoice, otherwise use order discount amount
        $discountAmount = $this->invoice ? $this->invoice->getDiscountAmount() : $order->getDiscountAmount();

        // Calculate total discount amount
        if (is_numeric($discountAmount))
        {
            $totalDiscountAmount = abs($this->helper->convertMagentoAmountToStripeAmount(
                $discountAmount,
                $order->getOrderCurrencyCode()
            ));
        }

        return $totalDiscountAmount;
    }

    public function getCustomerReference(): ?string
    {
        $order = $this->order;

        if ($order->getCustomerId())
        {
            return 'customer_' . $order->getCustomerId();
        }

        return null;
    }

    public function getAmountDetails(): array
    {
        $amountDetails = [];

        // Add line items if available
        if (!empty($this->lineItems)) {
            $lineItems = [];

            foreach ($this->lineItems as $item) {
                $lineItem = [
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'unit_cost' => $item['unit_cost'],
                    'quantity' => $item['quantity'],
                    'unit_of_measure' => $item['unit_of_measure']
                ];

                if (isset($item['tax']['total_tax_amount']) && $item['tax']['total_tax_amount'] > 0) {
                    $lineItem['tax'] = [
                        'total_tax_amount' => $item['tax']['total_tax_amount']
                    ];
                }

                if (isset($item['discount_amount']) && $item['discount_amount'] > 0) {
                    $lineItem['discount_amount'] = $item['discount_amount'];
                }

                if (isset($item['payment_method_options']) && !empty($item['payment_method_options'])) {
                    $lineItem['payment_method_options'] = $item['payment_method_options'];
                }

                $lineItems[] = $lineItem;
            }

            if (count($lineItems) > 0) {
                $amountDetails['line_items'] = $lineItems;
            }
        }

        // Add tax amount if no line item taxes exist
        if (!$this->hasLineItemTaxes) {
            $totalTaxAmount = $this->getTotalTaxAmount();
            if ($totalTaxAmount > 0) {
                $amountDetails['tax']['total_tax_amount'] = $totalTaxAmount;
            }
        }

        // Add shipping details if order is not virtual
        $shipping = $this->getShippingDetails();
        if (!empty($shipping)) {
            $amountDetails['shipping'] = $shipping;
        }

        // Add discount amount if no line item discounts exist
        if (!$this->hasLineItemDiscounts) {
            $totalDiscountAmount = $this->getTotalDiscountAmount();
            if (is_numeric($totalDiscountAmount) && $totalDiscountAmount > 0) {
                $amountDetails['discount_amount'] = $totalDiscountAmount;
            }
        }

        return $amountDetails;
    }

    public function getPaymentDetails(): array
    {
        $paymentDetails = [];

        // Always include order reference
        $orderReference = $this->getOrderReference();
        if (!empty($orderReference)) {
            $paymentDetails['order_reference'] = $orderReference;
        }

        // Include customer reference if available
        $customerReference = $this->getCustomerReference();
        if (!empty($customerReference)) {
            $paymentDetails['customer_reference'] = $customerReference;
        }

        return $paymentDetails;
    }

    /**
     * The calculated amount should be
     *
     * Sum(
     *   line_items[#].unit_cost * line_items[#].quantity +
     *   line_items[#].tax.total_tax_amount -
     *   line_items[#].discount_amount
     * ) + shipping.amount
     *
     * or
     *
     * Sum(line_items[#].unit_cost * line_items[#].quantity) +
     *   tax.total_tax_amount +
     *   shipping.amount -
     *   discount_amount
     */
    public function isGrandTotalEqualToLineItemsTotal($data)
    {
        $order = $this->order;
        $calculatedAmount = 0;

        if (isset($data['amount_details']['line_items']) && is_array($data['amount_details']['line_items']))
        {
            foreach ($data['amount_details']['line_items'] as $item)
            {
                $lineItemTotal = $item['unit_cost'] * $item['quantity'];

                if (isset($item['tax']['total_tax_amount']) && is_numeric($item['tax']['total_tax_amount']))
                {
                    $lineItemTotal += $item['tax']['total_tax_amount'];
                }

                if (isset($item['discount_amount']) && is_numeric($item['discount_amount']))
                {
                    $lineItemTotal -= $item['discount_amount'];
                }

                $calculatedAmount += $lineItemTotal;
            }
        }

        if (isset($data['amount_details']['shipping']['amount']) && is_numeric($data['amount_details']['shipping']['amount']))
        {
            $calculatedAmount += $data['amount_details']['shipping']['amount'];
        }

        if (isset($data['amount_details']['tax']['total_tax_amount']) && is_numeric($data['amount_details']['tax']['total_tax_amount']))
        {
            $calculatedAmount += $data['amount_details']['tax']['total_tax_amount'];
        }

        if (isset($data['amount_details']['discount_amount']) && is_numeric($data['amount_details']['discount_amount']))
        {
            $calculatedAmount -= $data['amount_details']['discount_amount'];
        }

        // Compare against invoice grand total if working with an invoice, otherwise use order grand total
        $expectedGrandTotal = $this->invoice ? $this->invoice->getGrandTotal() : $order->getGrandTotal();
        $this->loggerHelper->log($expectedGrandTotal);

        $grandTotal = $this->helper->convertMagentoAmountToStripeAmount(
            $expectedGrandTotal,
            $order->getOrderCurrencyCode()
        );

        return ($calculatedAmount == $grandTotal);
    }
}
