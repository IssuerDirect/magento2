<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\ExpressCheckout\Normal;

/**
 * Replicates a customer-reported issue where Chinese names coming from the
 * Express Checkout Element are not split into firstname/lastname correctly.
 *
 * Example: the name 李小龙 (surname 李, given name 小龙) fills only the
 * customer's firstname, because the name parser only splits on spaces.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChineseNameTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $helper;
    private $objectManager;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
    }

    /**
     * Directly tests the name parsing logic that the Express Checkout flow uses.
     * A Chinese name has no spaces, so the surname must be detected otherwise.
     */
    public function testChineseNameParsing()
    {
        $nameParserFactory = $this->objectManager
            ->get(\StripeIntegration\Payments\Model\Customer\NameParserFactory::class);

        // Most common case: 2-3 character name, single-character surname
        $payerName = $nameParserFactory->create()->fromString("李小龙");
        $this->assertEquals("小龙", $payerName->getFirstName());
        $this->assertEquals("李", $payerName->getLastName());
        $this->assertEmpty($payerName->getMiddleName());

        // Two-character name
        $payerName = $nameParserFactory->create()->fromString("李龙");
        $this->assertEquals("龙", $payerName->getFirstName());
        $this->assertEquals("李", $payerName->getLastName());

        // Compound surname (欧阳娜娜 -> 欧阳 + 娜娜)
        $payerName = $nameParserFactory->create()->fromString("欧阳娜娜");
        $this->assertEquals("娜娜", $payerName->getFirstName());
        $this->assertEquals("欧阳", $payerName->getLastName());

        // Compound surname in a 3-character name (司马光 -> 司马 + 光)
        $payerName = $nameParserFactory->create()->fromString("司马光");
        $this->assertEquals("光", $payerName->getFirstName());
        $this->assertEquals("司马", $payerName->getLastName());

        // CJK name with a space between the characters must still be split correctly
        $payerName = $nameParserFactory->create()->fromString("李 小龙");
        $this->assertEquals("小龙", $payerName->getFirstName());
        $this->assertEquals("李", $payerName->getLastName());

        // Western names must be unaffected
        $payerName = $nameParserFactory->create()->fromString("Bruce Lee");
        $this->assertEquals("Bruce", $payerName->getFirstName());
        $this->assertEquals("Lee", $payerName->getLastName());
    }

    /**
     * Runs the full Express Checkout Element flow with a Chinese name.
     *
     * With the bug present, the order placement fails with
     * 'Please check the shipping address information. "lastname" is required.'
     * because the name parser cannot split a space-less Chinese name into
     * firstname/lastname. Once the parsing is fixed, the order should be
     * placed with firstname=小龙, lastname=李.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testChineseNameExpressCheckout()
    {
        $product = $this->helper->loadProductBySku("simple-product");
        $request = [
            "product" => $product->getId(),
            "related_product" => "",
            "qty" => 1
        ];
        $result = $this->apiService->addtocart($request);
        $this->assertEquals("[]", $result);

        $address = $this->tests->address()->getExpressCheckoutElementFormat("NewYork");

        $result = $this->apiService->ece_shipping_address_changed($address, "checkout");
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["resolvePayload"]['lineItems']);
        $this->assertNotEmpty($data["resolvePayload"]['shippingRates']);

        $selectedShippingMethod = $data["resolvePayload"]['shippingRates'][0];
        $result = $this->apiService->ece_shipping_rate_changed($address, $selectedShippingMethod["id"]);
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["resolvePayload"]['lineItems']);
        $this->assertNotEmpty($data["resolvePayload"]['shippingRates']);

        $stripe = $this->stripeConfig->getStripeClient();
        $confirmationToken = $stripe->testHelpers->confirmationTokens->create([
            'payment_method' => 'pm_card_visa'
        ]);
        $this->assertNotEmpty($confirmationToken);
        $this->assertNotEmpty($confirmationToken->id);

        // Simulate the name Stripe returns for a customer paying with a
        // Chinese name (no spaces, surname first).
        $address = $this->tests->address()->getStripeFormat("NewYork");
        $address['name'] = "李小龙";

        $result = [
            "elementType" => "expressCheckout",
            "expressPaymentType" => "link",
            "billingDetails" => $address,
            "shippingAddress" => $address,
            "shippingRate" =>  $selectedShippingMethod,
            "confirmationToken" =>  $confirmationToken
        ];

        $result = $this->apiService->place_order($result, "product");
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data["redirect"]);
        $this->assertStringContainsString("stripe/payment/index", $data["redirect"]);

        $session = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->assertNotEmpty($session->getLastRealOrderId());
        $orderIncrementId = $session->getLastRealOrderId();

        $order = $this->tests->getLastOrder();
        $this->assertEquals($orderIncrementId, $order->getIncrementId());

        // The customer's name must be split correctly:
        // given name (firstname) = 小龙, surname (lastname) = 李
        $this->assertEquals("小龙", $order->getCustomerFirstname());
        $this->assertEquals("李", $order->getCustomerLastname());
        $this->assertEmpty($order->getCustomerMiddlename());

        // The order's shipping address must be split the same way
        $shippingAddress = $order->getShippingAddress();
        $this->assertEquals("小龙", $shippingAddress->getFirstname());
        $this->assertEquals("李", $shippingAddress->getLastname());
    }
}
