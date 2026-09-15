<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model\Webhooks;

use PHPUnit\Framework\TestCase;
use StripeIntegration\Payments\Model\Webhooks\MissingOrderHandler;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class MissingOrderHandlerTest extends TestCase
{
    private $objectManager;
    private $orderHelper;
    private $quoteHelper;
    private $config;
    private $webhooksHelper;
    private $stripePaymentIntentsCollection;
    private $stripePaymentIntentsCollectionFactory;
    private $logger;
    private $emailHelper;
    private $currencyHelper;
    private $missingOrderHandler;
    private $tests;
    private $convert;
    private $checkoutFlow;
    private $quoteManagement;
    private $addressRenderer;
    private $orderAddressFactory;
    private $chargeSucceededEvent;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        // Mock dependencies
        $this->orderHelper = $this->createMock(\StripeIntegration\Payments\Helper\Order::class);
        $this->quoteHelper = $this->createMock(\StripeIntegration\Payments\Helper\Quote::class);
        $this->convert = $this->createMock(\StripeIntegration\Payments\Helper\Convert::class);
        $this->emailHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Email::class);
        $this->logger = $this->createMock(\StripeIntegration\Payments\Helper\Logger::class);
        $this->currencyHelper = $this->createMock(\StripeIntegration\Payments\Helper\Currency::class);
        $this->webhooksHelper = $this->createMock(\StripeIntegration\Payments\Helper\Webhooks::class);
        $this->config = $this->createMock(\StripeIntegration\Payments\Model\Config::class);

        $this->stripePaymentIntentsCollection = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection::class);
        $this->stripePaymentIntentsCollectionFactory = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\CollectionFactory::class);
        $this->stripePaymentIntentsCollectionFactory->method('create')->willReturn($this->stripePaymentIntentsCollection);

        $this->checkoutFlow = $this->createMock(\StripeIntegration\Payments\Model\Checkout\Flow::class);
        $this->quoteManagement = $this->createMock(\Magento\Quote\Model\QuoteManagement::class);
        $this->addressRenderer = $this->createMock(\Magento\Sales\Model\Order\Address\Renderer::class);
        $this->orderAddressFactory = $this->createMock(\Magento\Sales\Model\Order\AddressFactory::class);
        $this->chargeSucceededEvent = $this->createMock(\StripeIntegration\Payments\Model\Stripe\Event\ChargeSucceeded::class);

        // Create the missingOrderHandler instance with mocked dependencies
        $this->missingOrderHandler = $this->objectManager->create(
            MissingOrderHandler::class,
            [
                'orderHelper' => $this->orderHelper,
                'quoteHelper' => $this->quoteHelper,
                'convert' => $this->convert,
                'emailHelper' => $this->emailHelper,
                'logger' => $this->logger,
                'currencyHelper' => $this->currencyHelper,
                'webhooksHelper' => $this->webhooksHelper,
                'config' => $this->config,
                'stripePaymentIntentsCollectionFactory' => $this->stripePaymentIntentsCollectionFactory,
                'checkoutFlow' => $this->checkoutFlow,
                'quoteManagement' => $this->quoteManagement,
                'addressRenderer' => $this->addressRenderer,
                'orderAddressFactory' => $this->orderAddressFactory,
                'chargeSucceededEvent' => $this->chargeSucceededEvent
            ]
        );
    }

    public function testFromEventReturnsEarlyIfNotChargeSucceeded()
    {
        $event = [
            'type' => 'payment_intent.succeeded' // Not charge.succeeded
        ];

        $result = $this->missingOrderHandler->fromEvent($event);

        $this->assertSame($this->missingOrderHandler, $result);
        $this->assertFalse($this->missingOrderHandler->wasOrderPlaced());
        $this->assertFalse($this->missingOrderHandler->wasAdminNotified());
        $this->assertNull($this->missingOrderHandler->getPlacedOrder());
    }

    public function testFromEventReturnsEarlyIfNoOrderMetadata()
    {
        $event = [
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'metadata' => [] // No Order # metadata
                ]
            ]
        ];

        $result = $this->missingOrderHandler->fromEvent($event);

        $this->assertSame($this->missingOrderHandler, $result);
        $this->assertFalse($this->missingOrderHandler->wasOrderPlaced());
        $this->assertFalse($this->missingOrderHandler->wasAdminNotified());
    }

    public function testFromEventReturnsEarlyIfChargeIsRecent()
    {
        $event = [
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'metadata' => [
                        'Order #' => '123456'
                    ],
                    'created' => time() - 60 // 1 minute old, less than 10 minutes
                ]
            ]
        ];

        $result = $this->missingOrderHandler->fromEvent($event);

        $this->assertSame($this->missingOrderHandler, $result);
        $this->assertFalse($this->missingOrderHandler->wasOrderPlaced());
        $this->assertFalse($this->missingOrderHandler->wasAdminNotified());
    }

    public function testFromEventReturnsEarlyIfOrderExists()
    {
        $orderIncrementId = '123456';

        $event = [
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'metadata' => [
                        'Order #' => $orderIncrementId
                    ],
                    'created' => time() - 900 // 15 minutes old, more than 10 minutes
                ]
            ]
        ];

        // Mock that the order actually exists
        $this->orderHelper->expects($this->once())
            ->method('loadOrderByIncrementId')
            ->with($orderIncrementId)
            ->willReturn(true);

        $result = $this->missingOrderHandler->fromEvent($event);

        $this->assertSame($this->missingOrderHandler, $result);
        $this->assertFalse($this->missingOrderHandler->wasOrderPlaced());
        $this->assertFalse($this->missingOrderHandler->wasAdminNotified());
    }

    public function testQuoteIsMissing()
    {
        $orderIncrementId = '123456';
        $paymentIntentId = 'pi_123456';
        $eventId = 'evt_123456';

        $event = [
            'id' => $eventId,
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'id' => 'ch_123456',
                    'metadata' => [
                        'Order #' => $orderIncrementId
                    ],
                    'created' => time() - 900, // 15 minutes old
                    'payment_intent' => $paymentIntentId
                ]
            ]
        ];

        // Mock that the order does not exist
        $this->orderHelper->expects($this->once())
            ->method('loadOrderByIncrementId')
            ->with($orderIncrementId)
            ->willReturn(false);

        // For DataObject, we need to create a real object rather than a mock
        $piModel = new \Magento\Framework\DataObject();
        $firstCollection = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection::class);
        $firstCollection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($piModel);

        $secondCollection = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection::class);
        $secondCollection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($piModel);

        // Set up the sequence of calls
        $this->stripePaymentIntentsCollection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnMap([
                ['order_increment_id', $orderIncrementId, $firstCollection],
                ['pi_id', $paymentIntentId, $secondCollection]
            ]);

        // We should log a webhook error
        $this->webhooksHelper->expects($this->once())
            ->method('log')
            ->with($this->stringContains("A charge.succeeded event arrived ($eventId), but we could not find the quote for order increment id: $orderIncrementId"));

        // Make sure the config returns that emails are enabled
        $this->config->expects($this->once())
            ->method('isMissingOrderEmailsEnabled')
            ->willReturn(true);

        $result = $this->missingOrderHandler->fromEvent($event);

        $this->assertSame($this->missingOrderHandler, $result);
        $this->assertFalse($this->missingOrderHandler->wasOrderPlaced());
    }

    public function testGrandTotalMismatch()
    {
        // Create a real quote
        $quoteHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $quote = $quoteHelper->create()
            ->setCustomer('Guest')
            ->addProduct('simple-product', 1)
            ->getQuote();

        // Set the quote currency code and grand total
        $quote->setQuoteCurrencyCode('USD');
        $quote->setGrandTotal(100.00);

        $this->convert->expects($this->once())
            ->method('magentoAmountToStripeAmount')
            ->with(100.00, 'USD')
            ->willReturn(10000); // $100.00 in cents

        $charge = [
            'amount' => 20000, // $200.00 in cents
            'currency' => 'usd'
        ];
        $this->assertFalse($this->tests->invoke($this->missingOrderHandler, 'grandTotalMatches', [$quote, $charge['amount']]));
    }

    public function testCurrencyMatches()
    {
        // Create a real quote
        $quoteHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $quote = $quoteHelper->create()
            ->setCustomer('Guest')
            ->addProduct('simple-product', 1)
            ->getQuote();

        // Set the quote currency code
        $quote->setQuoteCurrencyCode('USD');

        $chargeCurrency = 'usd';

        $this->assertTrue($this->tests->invoke($this->missingOrderHandler, 'currencyMatches', [$quote, $chargeCurrency]));
    }

    public function testCurrencyMismatch()
    {
        $quoteHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $quote = $quoteHelper->create()
            ->setCustomer('Guest')
            ->addProduct('simple-product', 1)
            ->getQuote();

        $quote->setQuoteCurrencyCode('USD');

        $chargeCurrency = 'eur'; // Different currency

        $this->assertFalse($this->tests->invoke($this->missingOrderHandler, 'currencyMatches', [$quote, $chargeCurrency]));
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/missing_order_emails 0
     */
    public function testEmailsDisabled()
    {
        $this->config->expects($this->once())
            ->method('isMissingOrderEmailsEnabled')
            ->willReturn(false);

        $event = [
            'type' => 'charge.succeeded'
        ];

        $this->missingOrderHandler->fromEvent($event);

        $this->assertTrue($this->missingOrderHandler->areEmailsDisabled());
    }

    public function testLoadQuoteByOrderIncrementId()
    {
        $orderIncrementId = '123456';
        $paymentIntentId = 'pi_123456';

        // Create a real quote
        $quoteHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $quote = $quoteHelper->create()
            ->setCustomer('Guest')
            ->addProduct('simple-product', 1)
            ->getQuote();

        $quote->save();
        $quoteId = $quote->getId();

        // Test scenario where we find the quote by order increment ID
        $piModel1 = new \Magento\Framework\DataObject(['quote_id' => $quoteId]);

        $this->stripePaymentIntentsCollection->expects($this->exactly(1))
            ->method('addFieldToFilter')
            ->with('order_increment_id', $orderIncrementId)
            ->willReturnSelf();

        $this->stripePaymentIntentsCollection->expects($this->exactly(1))
            ->method('getFirstItem')
            ->willReturn($piModel1);

        // Use the real Quote helper instead of the mock
        $realQuoteHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Quote::class);

        // Replace the mocked quoteHelper with the real one for this test
        $reflectionProperty = new \ReflectionProperty($this->missingOrderHandler, 'quoteHelper');
        $originalQuoteHelper = $reflectionProperty->getValue($this->missingOrderHandler);
        $reflectionProperty->setValue($this->missingOrderHandler, $realQuoteHelper);

        try {
            $result = $this->tests->invoke($this->missingOrderHandler, 'loadQuoteByOrderIncrementId', [$orderIncrementId, $paymentIntentId]);
            $this->assertEquals($quoteId, $result->getId());
        } finally {
            // Restore the original mocked quoteHelper
            $reflectionProperty->setValue($this->missingOrderHandler, $originalQuoteHelper);
        }
    }

    public function testLoadQuoteByOrderIncrementIdWithFallback()
    {
        $orderIncrementId = '123456';
        $paymentIntentId = 'pi_123456';

        // Create a real quote
        $quoteHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $quote = $quoteHelper->create()
            ->setCustomer('Guest')
            ->addProduct('simple-product', 1)
            ->getQuote();

        $quote->save();
        $quoteId = $quote->getId();

        // First lookup by order increment ID returns no quote ID
        $piModel1 = new \Magento\Framework\DataObject(); // Empty object, getQuoteId will return null

        // Second lookup by payment intent ID returns a quote ID
        $piModel2 = new \Magento\Framework\DataObject(['quote_id' => $quoteId]);

        // Create a mock for each call to handle sequencing without at() method
        $firstCollection = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection::class);
        $firstCollection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($piModel1);

        $secondCollection = $this->createMock(\StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection::class);
        $secondCollection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($piModel2);

        // Set up the sequence of calls
        $this->stripePaymentIntentsCollection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnMap([
                ['order_increment_id', $orderIncrementId, $firstCollection],
                ['pi_id', $paymentIntentId, $secondCollection]
            ]);

        // Use the real Quote helper instead of the mock
        $realQuoteHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Quote::class);

        // Replace the mocked quoteHelper with the real one for this test
        $reflectionProperty = new \ReflectionProperty($this->missingOrderHandler, 'quoteHelper');
        $originalQuoteHelper = $reflectionProperty->getValue($this->missingOrderHandler);
        $reflectionProperty->setValue($this->missingOrderHandler, $realQuoteHelper);

        try {
            $result = $this->tests->invoke($this->missingOrderHandler, 'loadQuoteByOrderIncrementId', [$orderIncrementId, $paymentIntentId]);
            $this->assertEquals($quoteId, $result->getId());
        } finally {
            // Restore the original mocked quoteHelper
            $reflectionProperty->setValue($this->missingOrderHandler, $originalQuoteHelper);
        }
    }

}
