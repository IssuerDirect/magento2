<?php

namespace StripeIntegration\Payments\Api\Response;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class ECEResponse
{
    // Constructor dependencies
    private $serializer;
    private $directoryHelper;
    private $scopeConfig;
    private $estimateAddressFactory;
    private $shippingConfig;
    private $priceCurrency;
    private $taxHelper;
    private $shipmentEstimation;
    private $taxCalculation;
    private $allowedCountries;
    private $initParams;
    private $helper;
    private $addressHelper;
    private $quoteHelper;
    private $productHelper;

    // Local data
    private $resolvePayload = [];
    private $elementOptions = [];
    private $expressCheckoutOptions = [];
    private $quote;
    private $location;

    public function __construct(
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Directory\Helper\Data $directoryHelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Api\Data\EstimateAddressInterfaceFactory $estimateAddressFactory,
        \Magento\Shipping\Model\Config $shippingConfig,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Quote\Api\ShipmentEstimationInterface $shipmentEstimation,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculation,
        \Magento\Directory\Model\AllowedCountries $allowedCountries,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        $location = null
    )
    {
        $this->serializer = $serializer;
        $this->directoryHelper = $directoryHelper;
        $this->scopeConfig = $scopeConfig;
        $this->estimateAddressFactory = $estimateAddressFactory;
        $this->shippingConfig = $shippingConfig;
        $this->priceCurrency = $priceCurrency;
        $this->taxHelper = $taxHelper;
        $this->shipmentEstimation = $shipmentEstimation;
        $this->taxCalculation = $taxCalculation;
        $this->allowedCountries = $allowedCountries;
        $this->initParams = $initParams;
        $this->helper = $helper;
        $this->addressHelper = $addressHelper;
        $this->quoteHelper = $quoteHelper;
        $this->productHelper = $productHelper;

        // Local data
        $this->quote = $this->quoteHelper->getQuote();
        $this->location = $location;
    }

    public function fromClickAt($location, $productId = null, $attribute = null)
    {
        switch ($location)
        {
            case 'checkout':
            case 'cart':
            case 'minicart':
                $this->resolvePayload = $this->getClickResolvePayload($location);
                $this->elementOptions = $this->initParams->getExpressCheckoutElementsOptions($this->resolvePayload);
                $this->expressCheckoutOptions = $this->initParams->getExpressCheckoutWalletOptions($this->quote, null, $location);
                break;
            default: // Product page
                if (is_numeric($productId))
                {
                    $this->resolvePayload = $this->getProductResolvePayload($productId, $attribute);
                    $this->elementOptions = $this->initParams->getExpressCheckoutElementsOptions($this->resolvePayload, $productId);
                    $this->expressCheckoutOptions = $this->initParams->getExpressCheckoutWalletOptions($this->quote, $productId, null);
                    $this->resolvePayload['lineItems'] = []; // This should be unset after getElementOptions(), because we still need the elementOptions['amount'] value, otherwise ECE wont display
                }
                else
                {
                    throw new CouldNotSaveException(__("Invalid product ID"));
                }
                break;
        }

        if (empty($this->resolvePayload))
            return $this;

        return $this;
    }

    public function fromNewShippingAddress($newAddress)
    {
        $this->quote = $this->quoteHelper->getQuote();
        $shippingAddress = $this->quote->getShippingAddress();
        $newData = $this->addressHelper->getPartialMagentoAddressFromECEAddress($newAddress, __("shipping"));
        $this->clearPersonalAddressFields($shippingAddress);
        $shippingAddress->addData($newData);

        // Some carts are virtual, but still collect a shipping addess (by design)
        // Until a payment method is selected, we keep billing and shipping the same, so that taxes are calculated correctly for virtual carts.
        $billingAddress = $this->quote->getBillingAddress();
        $this->clearPersonalAddressFields($billingAddress);
        $billingAddress->addData($newData);

        // Save the quote and shipping address and collect new shipping rates
        $oldShippingMethod = $shippingAddress->getShippingMethod();
        $shippingAddress->setCollectShippingRates(true);

        $shippingRates = $this->getShippingRatesForQuoteShippingAddress();

        if (count($shippingRates) > 0)
        {
            if ($oldShippingMethod && in_array($oldShippingMethod, array_column($shippingRates, 'id')))
            {
                // Restore previous shipping method if it is still available
                $shippingAddress->setShippingMethod($oldShippingMethod);
            }
            else
            {
                // Set the first available shipping method
                $shippingAddress->setShippingMethod($shippingRates[0]['id']);
            }
        }
        else
        {
            // Unset any existing shipping method from the quote
            $shippingAddress->setShippingMethod(null);
        }

        $this->quoteHelper->reCollectTotals($this->quote);
        $this->quoteHelper->saveQuote($this->quote);

        $this->resolvePayload = $this->getShippingResolvePayload();

        return $this;
    }

    public function fromNewShippingRate($shippingAddressData, $shippingMethodId)
    {
        $quote = $this->quote = $this->quoteHelper->getQuote();

        $newData = $this->addressHelper->getPartialMagentoAddressFromECEAddress($shippingAddressData, __("shipping"));

        $shippingAddress = $quote->getShippingAddress();
        $this->clearPersonalAddressFields($shippingAddress);
        $shippingAddress->addData($newData);

        // Some carts are virtual, but still collect a shipping address (by design)
        // Until a payment method is selected, we keep billing and shipping the same,
        // so that taxes are calculated correctly for virtual carts.
        $billingAddress = $quote->getBillingAddress();
        $this->clearPersonalAddressFields($billingAddress);
        $billingAddress->addData($newData);

        if ($shippingMethodId)
        {
            // Recollect available shipping rates for the new partial address, then select the one chosen in the ECE.
            // We deliberately avoid ShippingInformationManagement::saveAddressInformation() here because:
            //   1. It validates required address fields (firstname, lastname, telephone, street) which Apple Pay /
            //      Google Pay do not provide until the payment is confirmed.
            //   2. It would overwrite the partial ECE address with the customer's default address.
            //   3. Its remaining responsibilities (set shipping method, collect totals, save quote) are already
            //      handled below.
            $shippingAddress
                ->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod($shippingMethodId);
        }

        $this->quoteHelper->reCollectTotals($quote);
        $this->quote = $this->quoteHelper->saveQuote($quote);

        $this->resolvePayload = $this->getShippingResolvePayload();

        return $this;
    }

    /**
     * Clears address fields that are not provided by the ECE during the
     * shippingaddresschange / shippingratechange events. Apple Pay and Google Pay
     * only expose city / region / postal_code / country until the payment is
     * confirmed. Without this clear, stale values from a previous checkout visit
     * (e.g. the customer's saved firstname / street / telephone) would persist
     * on the quote address alongside the new partial location data.
     */
    private function clearPersonalAddressFields($address)
    {
        $fieldsToClear = [
            'firstname',
            'middlename',
            'lastname',
            'prefix',
            'suffix',
            'company',
            'street',
            'telephone',
            'fax',
            'email',
            'vat_id'
        ];

        foreach ($fieldsToClear as $field)
        {
            $address->setData($field, null);
        }
    }

    public function getData()
    {
        return [
            "resolvePayload" => $this->resolvePayload,
            "elementOptions" => $this->elementOptions,
            "expressCheckoutOptions" => $this->expressCheckoutOptions
        ];
    }

    public function serialize()
    {
        return $this->serializer->serialize($this->getData());
    }

    public function quoteHasCompleteShippingAddress()
    {
        return $this->addressHelper->quoteHasCompleteShippingAddress($this->quote);
    }

    protected function getClickResolvePayload($location = null)
    {
        $quoteHasItems = count($this->quote->getAllVisibleItems()) > 0;
        $requestShipping = ($quoteHasItems && !$this->quote->isVirtual());

        if ($location == "checkout" && $this->quoteHasCompleteShippingAddress())
        {
            $requestShipping = false;
        }

        $params = [
            'lineItems' => $this->getLineItems(),
        ];

        if ($requestShipping)
        {
            // The shipping address was not yet specified, or the quote is empty
            $params['shippingRates'] = $this->getDefaultShippingRates();
        }

        return $params;
    }

    public function getShippingResolvePayload()
    {
        $params = [
            'lineItems' => $this->getLineItems()
        ];

        if ($this->location == "checkout")
        {
            // This scenario should only hit with OneStepCheckout modules where the address was
            // not completed before the Wallet Button was clicked. Because if it was completed,
            // there would be no "shippingaddresschanged" event.
            $shippingRates = $this->getShippingRatesForQuoteShippingAddress();

            if (!empty($shippingRates))
            {
                $params['shippingRates'] = $shippingRates;
            }
            else
            {
                // Not passing any shipping rates will cause the event to be rejected
            }

            return $params;
        }
        else
        {
            if ($this->quote->isVirtual())
            {
                $params['shippingRates'] = $this->getFreeDeliveryRate();

                return $params;
            }
            else
            {
                $shippingRates = $this->getShippingRatesForQuoteShippingAddress();

                if (!empty($shippingRates))
                {
                    $params['shippingRates'] = $shippingRates;
                }
                else
                {
                    // Not passing any shipping rates will cause the event to be rejected
                }
            }
        }

        return $params;
    }


    /**
     * Get Express Checkout initialization params for Single Product
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getProductResolvePayload($productId, $attribute)
    {
        try
        {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $this->productHelper->getProduct($productId);
        }
        catch (\Exception $e)
        {
            return [];
        }

        $currency = $this->getCurrencyFromQuote();

        // Get Current Items in Cart
        $items = $this->getLineItems();
        $amount = 0;
        foreach ($items as $item)
        {
            $amount += $item['amount'];
        }

        if (!$this->quoteHelper->isProductInCart($productId))
        {
            $shouldInclTax = $this->shouldCartPriceInclTax();
            $productPrice = $this->productHelper->getPrice($product);
            $convertedFinalPrice = $this->priceCurrency->convertAndRound(
                $productPrice,
                null,
                $currency
            );

            $price = $this->getProductDataPrice(
                $product,
                $convertedFinalPrice,
                $shouldInclTax,
                $this->quote->getCustomerId(),
                $this->quote->getStore()->getStoreId()
            );

            // Append Current Product
            $productTotal = $this->helper->convertMagentoAmountToStripeAmount($price, $currency);
            $amount += $productTotal;

            $items[] = [
                'name' => $product->getName(),
                'amount' => $productTotal
            ];
        }

        $params = [
            'lineItems' => $items,
        ];

        $quoteHasItems = count($this->quote->getAllVisibleItems()) > 0;
        $requestShipping = ($quoteHasItems && !$this->quote->isVirtual()) || $this->productHelper->requiresShipping($product);

        if ($requestShipping)
        {
            // The shipping address was not yet specified, or the quote is empty
            $params['shippingRates'] = $this->getDefaultShippingRates();
        }
        else
        {
            // Case of virtual products / carts. We use the shipping address to calculate taxes
            $params['shippingRates'] = $this->getFreeDeliveryRate();
        }

        return $params;
    }

    public function getShippingRatesForQuoteShippingAddress()
    {
        $quote = $this->quote;
        $rates = [];

        if ($quote->isVirtual())
        {
            return [];
        }

        $rates = $this->shipmentEstimation->estimateByExtendedAddress($quote->getId(), $quote->getShippingAddress());

        if (empty($rates))
        {
            return [];
        }

        $shouldInclTax = $this->shouldCartPriceInclTax();
        $currency = $quote->getQuoteCurrencyCode();
        $selectedShippingMethod = $quote->getShippingAddress()->getShippingMethod();
        $result = [];
        foreach ($rates as $rate) {
            if ($rate->getErrorMessage()) {
                continue;
            }

            $rateData = [
                'id' => $rate->getCarrierCode() . '_' . $rate->getMethodCode(),
                'displayName' => implode(' - ', [$rate->getCarrierTitle(), $rate->getMethodTitle()]),
                //'detail' => $rate->getMethodTitle(),
                'amount' => $this->helper->convertMagentoAmountToStripeAmount($shouldInclTax ? $rate->getPriceInclTax() : $rate->getPriceExclTax(), $currency)
            ];

            // Add previously selected shipping method first, others after
            if ($selectedShippingMethod && $rateData['id'] === $selectedShippingMethod) {
                array_unshift($result, $rateData);
            } else {
                $result[] = $rateData;
            }
        }

        return $this->getLimitedShippingRates($result);
    }

    // The maximum amount of shipping rates for Express Checkout is 9
    public function getLimitedShippingRates($rates, $limit = 9)
    {
        return array_slice($rates, 0, $limit);
    }

    protected function getFreeDeliveryRate()
    {
        $shippingRates[] = [
            'id' => 'freeshipping_freeshipping',
            'amount' => 0,
            'displayName' => __('eDelivery')
        ];

        return $shippingRates;
    }

    protected function getDefaultShippingRates()
    {
        $countryCode = $this->getCountry();
        $estimateAddress = $this->estimateAddressFactory->create();
        $estimateAddress->setCountryId($countryCode);

        $shippingMethods = $this->getActiveShippingMethods();

        // Process the shipping methods to extract the required information
        $shippingRates = [];
        foreach ($shippingMethods as $shippingMethod) {
            $shippingRates[] = [
                'id' => $shippingMethod['carrier_code'] . '_' . $shippingMethod['method_code'],
                'amount' => 0,
                'displayName' => $shippingMethod['carrier_title'] . ' - ' . $shippingMethod['method_title']
            ];
        }

        return $this->getLimitedShippingRates($shippingRates);
    }

    /**
     * Get Country Code
     * @return string
     */
    protected function getCountry()
    {
        $countryCode = $this->quote->getBillingAddress()->getCountryId();
        if (empty($countryCode)) {
            $countryCode = $this->getDefaultCountry();
        }
        return $countryCode;
    }

    /**
     * Return default country code
     *
     * @param \Magento\Store\Model\Store|string|int $store
     * @return string
     */
    protected function getDefaultCountry($store = null)
    {
        $countryId = $this->directoryHelper->getDefaultCountry($store);

        if ($countryId)
            return $countryId;

        return $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_WEBSITES);
    }

    protected function getActiveShippingMethods()
    {
        $activeCarriers = $this->shippingConfig->getActiveCarriers();

        $shippingMethods = [];
        foreach ($activeCarriers as $carrierCode => $carrierModel) {
            if ($carrierModel->isActive()) {
                $allowedMethods = $carrierModel->getAllowedMethods();
                foreach ($allowedMethods as $methodCode => $methodTitle) {
                    $shippingMethods[] = [
                        'id' => $carrierCode . '_' . $methodCode,
                        'carrier_code' => $carrierCode,
                        'carrier_title' => $carrierModel->getConfigData('title'), // 'Flat Rate
                        'method_code' => $methodCode,
                        'method_title' => $methodTitle
                    ];
                }
            }
        }

        return $shippingMethods;
    }

    protected function getCurrencyFromQuote()
    {
        $currency = $this->quote->getQuoteCurrencyCode();
        if (empty($currency)) {
            $currency = $this->quote->getStore()->getCurrentCurrency()->getCode();
        }
        return $currency;
    }

    /**
     * Should Cart Price Include Tax
     *
     * @return bool
     */
    protected function shouldCartPriceInclTax()
    {
        $store = $this->quote->getStore();

        if ($this->taxHelper->displayCartBothPrices($store)) {
            return true;
        } elseif ($this->taxHelper->displayCartPriceInclTax($store)) {
            return true;
        }

        return false;
    }

    /**
     * Get Line Items
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getLineItems()
    {
        // Get Currency
        $currency = $this->quote->getQuoteCurrencyCode();
        if (empty($currency)) {
            $currency = $this->quote->getStore()->getCurrentCurrency()->getCode();
        }

        // Get Quote Items
        // Use address totals instead of quote totals because quote->getTotals() can return stale/cached values
        $lineItems = [];
        if ($this->quote->isVirtual()) {
            $totals = $this->quote->getBillingAddress()->getTotals();
        } else {
            $totals = $this->quote->getShippingAddress()->getTotals();
        }
        $grandTotal = 0;

        foreach ($totals as $total)
        {
            $code = $total->getCode();
            $title = $total->getTitle();
            $value = $total->getValue();

            if ($code == "grand_total")
                continue;

            if (!is_numeric($value))
                continue;

            if ($value == 0 && $code != "tax")
                continue;

            $lineItems[] = [
                'name' => $title,
                'amount' => $this->helper->convertMagentoAmountToStripeAmount($value, $currency, true),
            ];

            $grandTotal += $value;
        }

        if ($this->quote->getGrandTotal() != $grandTotal)
        {
            return [[
                'name' => __('Grand Total'),
                'amount' => $this->helper->convertMagentoAmountToStripeAmount($this->quote->getGrandTotal(), $currency, true),
            ]];
        }

        return $lineItems;
    }

    /**
     * Get Product Price with(without) Taxes
     * @param \Magento\Catalog\Model\Product $product
     * @param float|null $price
     * @param bool $inclTax
     * @param int $customerId
     * @param int $storeId
     *
     * @return float
     */
    protected function getProductDataPrice($product, $price = null, $inclTax = false, $customerId = null, $storeId = null)
    {
        if (!($taxAttribute = $product->getCustomAttribute('tax_class_id')))
            return $price;

        if (!$price) {
            $price = $product->getPrice();
        }

        $productRateId = $taxAttribute->getValue();
        $rate = $this->taxCalculation->getCalculatedRate($productRateId, $customerId, $storeId);
        if ((int) $this->scopeConfig->getValue(
            'tax/calculation/price_includes_tax',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) === 1
        ) {
            $priceExclTax = $price / (1 + ($rate / 100));
        } else {
            $priceExclTax = $price;
        }

        $priceInclTax = $priceExclTax + ($priceExclTax * ($rate / 100));

        return round($inclTax ? floatval($priceInclTax) : floatval($priceExclTax), PriceCurrencyInterface::DEFAULT_PRECISION);
    }

}