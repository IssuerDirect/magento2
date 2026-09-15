<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\InvalidAddressException;
use Magento\Store\Model\ScopeInterface;

class Address
{
    private $countryFactory;
    private $directoryHelper;
    private $nameParserFactory;
    private $allowedCountries;

    public function __construct(
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Helper\Data $directoryHelper,
        \StripeIntegration\Payments\Model\Customer\NameParserFactory $nameParserFactory,
        \Magento\Directory\Model\AllowedCountries $allowedCountries
    ) {
        $this->countryFactory = $countryFactory;
        $this->directoryHelper = $directoryHelper;
        $this->nameParserFactory = $nameParserFactory;
        $this->allowedCountries = $allowedCountries;
    }

    public function getStripeAddressFromMagentoAddress($address)
    {
        if (empty($address))
            return null;

        $data = [
            "address" => [
                "line1" => $address->getStreetLine(1),
                "line2" => $address->getStreetLine(2),
                "city" => $address->getCity(),
                "country" => $address->getCountryId(),
                "postal_code" => $address->getPostcode(),
                "state" => $address->getRegion()
            ],
            "name" => $address->getName(),
            "email" => $address->getEmail(),
            "phone" => substr((string)$address->getTelephone(), 0, 20)
        ];

        foreach ($data['address'] as $key => $value) {
            if (empty($data['address'][$key]))
                unset($data['address'][$key]);
        }

        foreach ($data as $key => $value) {
            if (empty($data[$key]))
                unset($data[$key]);
        }

        return $data;
    }

    public function getStripeShippingAddressFromMagentoAddress($address)
    {
        if (empty($address))
            return null;

        $data = [
            "address" => [
                "line1" => $address->getStreetLine(1),
                "line2" => $address->getStreetLine(2),
                "city" => $address->getCity(),
                "country" => $address->getCountryId(),
                "postal_code" => $address->getPostcode(),
                "state" => $address->getRegion()
            ],
            "carrier" => null,
            "name" => $address->getFirstname() . " " . $address->getLastname(),
            "phone" => $address->getTelephone(),
            "tracking_number" => null
        ];

        foreach ($data['address'] as $key => $value) {
            if (empty($data['address'][$key]))
                unset($data['address'][$key]);
        }

        foreach ($data as $key => $value) {
            if (empty($data[$key]))
                unset($data[$key]);
        }

        return $data;
    }

    public function getMagentoAddressFromECEAddress($data)
    {
        $fullName = $data['name'] ?? null;
        $payerName = $this->nameParserFactory->create()->fromString($fullName);
        $region = $data['address']['state'] ?? null;
        $country = $data['address']['country'] ?? null;

        // Get Region Id
        $regionId = $this->getRegionIdBy($regionName = $region, $regionCountry = $country);

        return [
            'firstname' => $payerName->getFirstName(),
            'middlename' => $payerName->getMiddleName(),
            'lastname' => $payerName->getLastName(),
            'company' => $data['address']['organization'] ?? null,
            'email' => $data['email'] ?? null,
            'street' => [
                0 => $data['address']['line1'] ?? '',
                1 => $data['address']['line2'] ?? ''
            ],
            'city' => $data['address']['city'] ?? null,
            'region_id' => $regionId,
            'region' => $region,
            'postcode' => $data['address']['postal_code'] ?? null,
            'country_id' => $country,
            'telephone' => $data['phone'] ?? null,
            'fax' => null,
        ];
    }

    public function getMagentoShippingAddressFromECEResult($result)
    {
        if (empty($result['shippingAddress']['address'])) {
            throw new InvalidAddressException(__("Invalid shipping address."));
        } else {
            $shippingAddress = $result['shippingAddress']['address'];

            if (empty($shippingAddress['country'])) {
                throw new InvalidAddressException(__("Invalid shipping address country."));
            }

            if (empty($shippingAddress['line1'])) {
                throw new InvalidAddressException(__("Invalid shipping address street."));
            }
        }

        $billingDetails = $result['billingDetails'] ?? [];

        $fullName = $this->nameParserFactory->create();
        if (!empty($result['shippingAddress']['name'])) {
            $fullName->fromString($result['shippingAddress']['name']);
        } else if (!empty($billingDetails['name'])) {
            $fullName->fromString($billingDetails['name']);
        }

        $regionId = $this->getRegionIdBy($regionName = $shippingAddress['state'] ?? null, $regionCountry = $shippingAddress['country'] ?? null);

        if (!$regionId && $this->isRegionRequired($shippingAddress['country'])) {
            throw new InvalidAddressException(__("Invalid shipping address region."));
        }

        return [
            'firstname' => $fullName->getFirstName(),
            'middlename' => $fullName->getMiddleName(),
            'lastname' => $fullName->getLastName(),
            'company' => $shippingAddress['organization'] ?? null,
            'email' => $result['shippingAddress']['email'] ?? $billingDetails['email'] ?? null,
            'street' => [
                0 => $shippingAddress['line1'] ?? '',
                1 => $shippingAddress['line2'] ?? ''
            ],
            'city' => $shippingAddress['city'] ?? null,
            'region_id' => $regionId,
            'region' => $shippingAddress['state'] ?? null,
            'postcode' => $shippingAddress['postal_code'] ?? null,
            'country_id' => $shippingAddress['country'],
            'telephone' => $result['shippingAddress']['phone'] ?? $billingDetails['phone'] ?? null,
            'fax' => null
        ];
    }

    public function getPartialMagentoAddressFromECEAddress($address, $addressType)
    {
        if (!is_array($address) || empty($address['country']))
            throw new InvalidAddressException(__("Invalid %1 country.", $addressType));

        $region = $address['state'] ?? null;
        $country = $address['country'] ?? null;
        $regionId = $this->getRegionIdBy($region, $country);

        return [
            'city' => $address['city'] ?? null,
            'region_id' => $regionId,
            'region' => $region,
            'postcode' => $address['postal_code'] ?? null,
            'country_id' => $country
        ];
    }

    public function getRegionIdBy($regionName, $regionCountry)
    {
        if (empty($regionCountry))
            return null;

        if (empty($regionName))
            return null;

        $regions = $this->getRegionsForCountry($regionCountry);

        $regionName = $this->clean($regionName);

        if (isset($regions['byName'][$regionName]))
            return $regions['byName'][$regionName];
        else if (isset($regions['byCode'][$regionName]))
            return $regions['byCode'][$regionName];

        return null;
    }

    public function getRegionsForCountry($countryCode)
    {
        $values = [];

        if (empty($countryCode))
            return $values;

        $country = $this->countryFactory->create()->loadByCode($countryCode);

        if (empty($country))
            return $values;

        $regions = $country->getRegions();

        foreach ($regions as $region)
        {
            $values['byCode'][$this->clean($region->getCode())] = $region->getId();
            $values['byName'][$this->clean($region->getName())] = $region->getId();
        }

        return $values;
    }

    public function clean($str)
    {
        if (empty($str))
            return null;

        return strtolower(trim($str));
    }

    public function convertCamelCaseKeysToSnakeCase(array $elements): array
     {
        $output = [];

        foreach ($elements as $key => $value)
        {
            $newKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $output[$newKey] = $value;
        }

        return $output;
     }

    public function filterAddressData($data)
    {
        $allowed = ['prefix', 'firstname', 'middlename', 'lastname', 'email', 'suffix', 'company', 'street', 'city', 'country_id', 'region', 'region_id', 'postcode', 'telephone', 'fax', 'vat_id'];
        $remove = [];

        $data = $this->convertCamelCaseKeysToSnakeCase($data);

        foreach ($data as $key => $value)
        {
            if (!in_array($key, $allowed))
                $remove[] = $key;
        }

        foreach ($remove as $key)
        {
            unset($data[$key]);
        }

        return $data;
    }

    public function filterAddressDataAndRemoveEmpty($data, $additionalFieldsToRemove = [])
    {
        $data = $this->filterAddressData($data);

        foreach ($data as $key => $value)
        {
            if (empty($value) || in_array($key, $additionalFieldsToRemove))
                unset($data[$key]);
        }

        return $data;
    }

    public function isRegionRequired($countryCode)
    {
        return $this->directoryHelper->isRegionRequired($countryCode);
    }

    public function getShippingAddressFromOrder($order)
    {
        if (empty($order) || $order->getIsVirtual())
            return null;

        $address = $order->getShippingAddress();

        if (empty($address))
            return null;

        if (empty($address->getFirstname()))
            return null;

        return $this->getStripeShippingAddressFromMagentoAddress($address);
    }

    public function isMagentoBillingAddressValid($billingAddress)
    {
        if (empty($billingAddress))
            return false;

        if (empty($billingAddress['country_id']))
            return false;

        if (empty($billingAddress['city']))
            return false;

        if (empty($billingAddress['street'][0]))
            return false;

        return true;
    }

    public function getFirstnameFromStripeAddress($stripeAddress)
    {
        if (empty($stripeAddress))
            return null;

        if (empty($stripeAddress['name']))
            return null;

        $payerName = $this->nameParserFactory->create()->fromString($stripeAddress['name']);

        return $payerName->getFirstName();
    }

    public function getLastnameFromStripeAddress($stripeAddress)
    {
        if (empty($stripeAddress))
            return null;

        if (empty($stripeAddress['name']))
            return null;

        $payerName = $this->nameParserFactory->create()->fromString($stripeAddress['name']);

        return $payerName->getLastName();
    }

    public function getPhoneFromStripeAddress($stripeAddress)
    {
        return $stripeAddress['phone'] ?? null;
    }

    public function getEmailFromStripeAddress($stripeAddress)
    {
        return $stripeAddress['email'] ?? null;
    }

    public function quoteHasCompleteShippingAddress($quote)
    {
        $shippingAddress = $quote->getShippingAddress();
        $address = $this->getStripeAddressFromMagentoAddress($shippingAddress);

        if (!empty($address["address"]["line1"])
            && !empty($address["address"]["city"])
            && !empty($address["address"]["country"])
            && !empty($address["address"]["postal_code"])
        ) {
            return true;
        }

        return false;
    }

    public function getAllowedShippingCountries()
    {
        $storeScope = ScopeInterface::SCOPE_STORES;
        $countries = $this->allowedCountries->getAllowedCountries($storeScope);
        $unsupportedCountries = ["AS", "CX", "CC", "CU", "HM", "IR", "MH", "FX", "FM", "AN", "NF", "KP", "MP", "PW", "SD", "SY", "VI", "UM"];
        foreach ($unsupportedCountries as $countryCode)
        {
            if (isset($countries[$countryCode]))
                unset($countries[$countryCode]);
        }
        return $countries;
    }
}
