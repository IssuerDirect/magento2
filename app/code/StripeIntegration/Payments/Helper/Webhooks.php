<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Exception\OrderNotFoundException;
use StripeIntegration\Payments\Exception\OrderPlacementInProgressException;
use StripeIntegration\Payments\Exception\RetryLaterException;
use StripeIntegration\Payments\Exception\SubscriptionUpdatedException;
use StripeIntegration\Payments\Exception\MissingOrderException;

class Webhooks
{
    private $output = null;
    private $debug = false;
    private $webhooksLogger;
    private $eventManager;
    private $invoiceFactory;
    private $paymentElementFactory;
    private $config;
    private $webhookCollection;
    private $webhookEventCollectionFactory;
    private $paymentIntentFactory;
    private $checkoutSessionFactory;
    private $webhookEventFactory;
    private $emailHelper;
    private $response;
    private $request;
    private $subscriptionsHelper;
    private $helper;
    private $cache;
    private $orderHelper;
    private $missingOrderHandlerFactory;
    private $invoicePaymentsHelper;
    private $stripeSubscriptionModelFactory;

    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\Http $response,
        \StripeIntegration\Payments\Logger\WebhooksLogger $webhooksLogger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Webhooks\MissingOrderHandlerFactory $missingOrderHandlerFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhookCollection,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Model\WebhookEventFactory $webhookEventFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionModelFactory,
        \StripeIntegration\Payments\Helper\Email $emailHelper,
        \Magento\Framework\App\CacheInterface $cache,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->webhooksLogger = $webhooksLogger;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->eventManager = $eventManager;
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->config = $config;
        $this->missingOrderHandlerFactory = $missingOrderHandlerFactory;
        $this->webhookCollection = $webhookCollection;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->webhookEventFactory = $webhookEventFactory;
        $this->emailHelper = $emailHelper;
        $this->cache = $cache;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->stripeSubscriptionModelFactory = $stripeSubscriptionModelFactory;
    }

    public function setOutput(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->output = $output;
    }

    protected function verifyRequestMethod()
    {
        if ($this->request->getMethod() == 'GET')
            throw new WebhookException("Your webhooks endpoint is accessible from your location.", 200);
    }

    public function dispatchEvent($stdEvent = null, $processMoreThanOnce = false)
    {
        $webhookEventModel = null;

        try
        {
            if (!$stdEvent)
            {
                $this->verifyRequestMethod();

                // Retrieve the request's body and parse it as JSON
                $body = $this->request->getContent();
                $event = json_decode($body, true);
                $stdEvent = json_decode($body);

                $eventType = $this->getEventType($event);

                if (isset($event['id']))
                    $eventId = " (" . $event['id'] . ")";
                else
                    $eventId = "";

                $this->log("Received $eventType" . $eventId);

                $this->verifyWebhookSignature();
            }
            else
            {
                $event = json_decode(json_encode($stdEvent), true);

                $eventType = $this->getEventType($event);

                if (isset($event['id']))
                    $eventId = " (" . $event['id'] . ")";
                else
                    $eventId = "";

                $this->log("Received $eventType" . $eventId);
            }

            $webhookModel = $this->webhookCollection->findFromRequest($this->request->getContent(), $this->request->getHeader('Stripe-Signature'));
            if ($webhookModel && $webhookModel->getId())
            {
                $webhookModel->pong()->save();
            }

            $webhookEventModel = $this->webhookEventFactory->create()->fromStripeObject($event, $processMoreThanOnce);

            if ($event['type'] == "product.created")
            {
                $this->onProductCreated($event, $stdEvent);
                $webhookEventModel->markAsProcessed();
                $this->log("200 OK" . $eventId);
                return;
            }

            $this->response->setStatusCode(500);
            $this->eventManager->dispatch($eventType, [
                'arrEvent' => $event,
                'stdEvent' => $stdEvent,
                'object' => $event['data']['object'],
                'paymentMethod' => $this->getPaymentMethodFrom($event)
            ]);

            $this->response->setStatusCode(200);

            $webhookEventModel->markAsProcessed();
            $this->log("200 OK" . $eventId);
        }
        catch (OrderNotFoundException $e)
        {
            if (isset($event) && is_array($event))
            {
                $handler = $this->missingOrderHandlerFactory->create()->fromEvent($event);
                if ($handler->wasOrderPlaced() || $handler->wasAdminNotified() || $handler->areEmailsDisabled())
                {
                    $this->response->setStatusCode(200);
                    $webhookEventModel->markAsProcessed();
                    if ($handler->wasOrderPlaced())
                    {
                        $this->log("200 There was no matching order in Magento. A new order #" . $handler->getPlacedOrder()->getIncrementId() ." was created. (" . $event['id'] . ")");
                    }
                    else
                    {
                        $this->log("202 There is no matching order in Magento (" . $event['id'] . ")");
                    }
                    return;
                }
            }

            $statusCode = 200;

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $e->statusCode);
            }

            $this->response->setStatusCode($statusCode);
            $this->error(__("Event queued for processing (%1)", $event['id'] ?? ''), $statusCode, true);
        }
        catch (OrderPlacementInProgressException $e)
        {
            // Keep the event row (is_processed=0, last_error set) so checkout can process
            // it immediately after releasing the placement lock. Same pattern as OrderNotFound.
            $statusCode = 200;

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $e->statusCode);
            }

            $this->response->setStatusCode($statusCode);
            $this->error(__("Event queued for processing — order placement in progress (%1)", $event['id'] ?? ''), $statusCode, true);
        }
        catch (RetryLaterException $e)
        {
            $statusCode = 409;
            $webhookEventModel->delete();
            $this->response->setStatusCode($statusCode);
            $this->error(__($e->getMessage()), $statusCode, true);
        }
        catch (WebhookException $e)
        {
            if (!empty($e->statusCode))
                $this->response->setStatusCode($e->statusCode);
            else
                $this->response->setStatusCode(202);

            $statusCode = $this->response->getStatusCode();

            $this->error($e->getMessage(), $statusCode, true);

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $statusCode);
            }
        }
        catch (\Throwable $e)
        {
            $statusCode = 500;
            $this->response->setStatusCode($statusCode);

            $this->log($e->getMessage());
            $this->log($e->getTraceAsString());
            $this->error($e->getMessage(), $statusCode);

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $statusCode);
            }
        }
    }

    protected function getEventType(array $event)
    {
        if (empty($event['type']))
            return "payload with no event type";

        $eventType = "stripe_payments_webhook_" . str_replace(".", "_", $event['type']);
        return $eventType;
    }

    protected function getPaymentMethodFrom($event)
    {
        if (isset($event['data']['object']['type']))
            $paymentMethod = $event['data']['object']['type'];
        else if (isset($event['data']['object']['payment_method_types']))
            $paymentMethod = implode("_", $event['data']['object']['payment_method_types']);
        else if (isset($event['data']['object']['payment_method_details']))
            $paymentMethod = $event['data']['object']['payment_method_details']['type'];
        else
            $paymentMethod = '';

        return $paymentMethod;
    }

    public function onProductCreated($event, $stdEvent)
    {
        if ($event['data']['object']['name'] == "Webhook Configuration")
        {
            $this->eventManager->dispatch("automatic_webhook_configuration", ['event' => $stdEvent]);
        }
        else if ($event['data']['object']['name'] == "Webhook Ping")
        {
            $this->webhookCollection->pong($event['data']['object']['metadata']['pk']);
        }
    }

    public function error($msg, $status, $displayError = false)
    {
        if ($this->output)
        {
            if ($status)
            {
                if ($status < 300)
                    return $this->output->writeln("$status $msg");
                else
                    return $this->output->writeln("<error>$status $msg</error>");
            }
            else
                return $this->output->writeln("<error>$msg</error>");
        }

        if ($status && $status > 0)
            $this->log("$status $msg");
        else
            $this->log("No status: $msg");

        if (!$displayError && !$this->debug)
            $msg = "An error has occurred. Please check var/log/stripe_payments_webhooks.log for more details.";

        $this->response
            ->setHeader('Content-Type', 'text/plain', $overwriteExisting = true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContent($msg);
    }

    public function log($msg)
    {
        if ($this->output)
            $this->output->writeln($msg);
        // Magento 2.0.0 - 2.4.3
        else if (method_exists($this->webhooksLogger, 'addInfo'))
            $this->webhooksLogger->addInfo($msg);
        // Magento 2.4.4+
        else
            $this->webhooksLogger->info($msg);
    }

    private function addError(&$errors, string $message)
    {
        $count = count($errors) + 1;
        if (!isset($errors[$message]))
        {
            $errors[$message] = "#" . $count . " " . $message;
        }
    }

    public function verifyWebhookSignature()
    {
        // If the webhook origin check is disabled, we skip the signature verification.
        if (!$this->config->isWebhookOriginCheckEnabled())
            return;

        // If the webhook origin check is enabled, we verify the signatures and throw an exception if none are found.
        // Signatures being empty now means that there are no signing secrets configured.
        $signingSecrets = $this->config->getWebhooksSigningSecrets();
        if (empty($signingSecrets))
            throw new WebhookException("Webhook origin check is enabled but no signing secrets are configured.", 400);

        $success = false;
        $errors = [];
        foreach ($signingSecrets as $signingSecret)
        {
            try
            {
                $httpStripeSignature = $this->request->getHeader('Stripe-Signature');
                if (empty($httpStripeSignature))
                {
                    $this->addError($errors, "Webhook signature could not be found in the request headers.");
                    continue;
                }

                // throws SignatureVerificationException
                $event = \Stripe\Webhook::constructEvent($this->request->getContent(), $httpStripeSignature, $signingSecret);

                $success = true;
            }
            catch(\UnexpectedValueException $e)
            {
                $this->addError($errors, $e->getMessage());
                throw new WebhookException("Invalid webhook payload.", 400);
            }
            catch(\Stripe\Exception\SignatureVerificationException $e)
            {
                continue;
            }
        }

        if (!$success)
        {
            if (empty($errors))
                $this->addError($errors, "Webhook signature could not be verified.");

            $this->log("Webhook origin check failed with " . count($errors) . " errors:\n" . implode("\n", $errors));
            throw new WebhookException("Webhook origin check failed.", 400);
        }
    }

    // Does not throw an exception
    public function getOrderIdFromObject(array $object, $includeMultishipping = false)
    {
        // For most payment methods, the order ID is here
        if (!empty($object['metadata']['Order #']))
            return $object['metadata']['Order #'];

        // Multishipping cases
        if (!empty($object['metadata']['Multishipping']) && !empty($object['metadata']['Orders']))
        {
            $data = $object['metadata']['Orders'];
            $data = str_replace(" ", "", $data);
            $data = str_replace("#", "", $data);
            return explode(",", $data);
        }

        if ($object['object'] == 'invoice')
        {
            // For invoices created from the Magento admin
            $entry = $this->invoiceFactory->create()->load($object['id'], 'invoice_id');
            if ($entry->getOrderIncrementId())
            {
                return $entry->getOrderIncrementId();
            }

            if (!empty($object["subscription"]))
            {
                // Fetch the subscription from Stripe and check for an order increment ID in the metadata.
                $stripeSubscriptionModel = $this->stripeSubscriptionModelFactory->create()->fromSubscriptionId($object["subscription"]);
                if ($stripeSubscriptionModel->getOrderIncrementId())
                {
                    return $stripeSubscriptionModel->getOrderIncrementId();
                }

                // If the subscription was updated, no new order exists. The quote will be used to create the new order
                $subscriptionModel = $this->subscriptionsHelper->loadSubscriptionModelBySubscriptionId($object['subscription']);
                if ($subscriptionModel && $subscriptionModel->getReorderFromQuoteId())
                {
                    throw new SubscriptionUpdatedException($subscriptionModel->getReorderFromQuoteId());
                }

                // Subscriptions that were just bought with PaymentElement do not have metadata yet
                $paymentElement = $this->paymentElementFactory->create()->load($object['subscription'], 'subscription_id');
                if ($paymentElement->getOrderIncrementId())
                {
                    return $paymentElement->getOrderIncrementId();
                }

                // This may be a more appropriate entry to check than PaymentElement, but it needs more testing before we swap them.
                if ($subscriptionModel && $subscriptionModel->getOrderIncrementId())
                {
                    return $subscriptionModel->getOrderIncrementId();
                }
            }

            // Subscriptions bought using Stripe Checkout
            foreach ($object['lines']['data'] as $lineItem)
            {
                if (!empty($lineItem['metadata']['Order #']))
                {
                    return $lineItem['metadata']['Order #'];
                }
            }
        }
        else if ($object['object'] == 'setup_intent')
        {
            $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
            if ($paymentElement->getOrderIncrementId())
            {
                return $paymentElement->getOrderIncrementId();
            }
        }
        else if ($object['object'] == 'refund' && !empty($object['payment_intent']))
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($object['payment_intent']);
            if (!empty($paymentIntent->metadata->{'Order #'}))
            {
                return $paymentIntent->metadata->{'Order #'};
            }
        }
        // If the merchant refunds a charge of a recurring subscription order from the Stripe dashboard, we need to drill down to the parent subscription
        else if ($incrementId = $this->getOrderIncrementIdFromCharge($object))
        {
            return $incrementId;
        }
        else if ($object['object'] == "checkout.session")
        {
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->load($object["id"], 'checkout_session_id');
            if ($checkoutSessionModel->getOrderIncrementId())
                return $checkoutSessionModel->getOrderIncrementId();
        }
        // Triggered via stripe_payments_webhook_review_closed
        else if (!empty($object['payment_intent']))
        {
            if ($includeMultishipping)
            {
                // Search for all orders which have this payment intent as a transaction ID
                $orders = $this->helper->getOrdersByTransactionId($object['payment_intent']);
                if (!empty($orders))
                {
                    $ids = [];
                    foreach ($orders as $order)
                        $ids[$order->getIncrementId()] = $order->getIncrementId();

                    return $ids;
                }
            }
            else
            {
                $paymentIntent = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
                $orderId = $paymentIntent->getOrderIncrementId();
                if ($orderId)
                    return $orderId;
            }
        }

        return null;
    }

    private function getOrderIncrementIdFromCharge($object)
    {
        if ($object['object'] != 'charge')
            return null;

        if (empty($object['customer']))
            return null;
        else
            $this->config->reInitStripeFromCustomerId($object['customer']);

        $stripe = $this->config->getStripeClient();

        $charge = $stripe->charges->retrieve($object['id'], ['expand' => ['payment_intent']]);

        if (!empty($charge->payment_intent->metadata->{"Order #"}))
        {
            return $charge->payment_intent->metadata->{"Order #"};
        }

        $invoice = $this->getInvoiceFromPaymentIntentId($charge->payment_intent->id ?? null);

        if (!empty($invoice->parent->subscription_details->subscription->metadata->{"Order #"}))
        {
            // Example hit:
            // - Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\FixedBundleMixedTrial
            return $invoice->parent->subscription_details->subscription->metadata->{"Order #"};
        }
        else if (!empty($invoice->metadata->{"Order #"}))
        {
            // Example hit:
            // - Test/Integration/Adminarea/BankTransfer/Normal/PlaceOrderTest.php
            // - Test/Integration/Adminarea/StripeInvoice/Normal/PartialRefundTest.php
            return $invoice->metadata->{"Order #"};
        }

        return null;
    }

    private function getInvoiceFromPaymentIntentId($paymentIntentId)
    {
        if (empty($paymentIntentId))
            return null;

        $invoicePayments = $this->invoicePaymentsHelper->listByPaymentIntentId($paymentIntentId);

        foreach ($invoicePayments->autoPagingIterator() as $invoicePayment) {
            if (!empty($invoicePayment->invoice)) {
                return $this->config->getStripeClient()->invoices->retrieve($invoicePayment->invoice, ['expand' => ['parent.subscription_details.subscription']]);
            }
        }

        return null;
    }

    public function getPaymentMethod(array $object)
    {
        // Most APMs
        if (!empty($object["type"]))
            return $object["type"];

        return null;
    }

    protected function recordOrderIdAgainstEvent($eventId, $orderIncrementId)
    {
        $webhookEventModel = $this->webhookEventFactory->create()->load($eventId, 'event_id');

        if (!$webhookEventModel->getId())
        {
            return;
        }

        if (is_array($orderIncrementId))
        {
            $webhookEventModel->setOrderIncrementId(implode(",", $orderIncrementId))->save();
        }
        else if (is_string($orderIncrementId))
        {
            $webhookEventModel->setOrderIncrementId($orderIncrementId)->save();
        }
    }

    // There are certain edge cases where the order ID is updated to a different one after the payment succeeded,
    // mainly due to checkout crashes. We fetch the latest payment intent and get the order ID from there.
    private function getOrderIdFromLatestEventObject($event)
    {
        if (empty($event['data']['object']['object']))
        {
            return null;
        }

        $objectType = $event['data']['object']['object'];

        if ($objectType == "payment_intent") {
            return $this->retrieveOrderIncrementIdFromPaymentIntent($event['data']['object']['id']);
        } elseif (($objectType == "charge" || $objectType == "dispute") &&
            !empty($event['data']['object']['payment_intent'])
        ) {
            return $this->retrieveOrderIncrementIdFromPaymentIntent($event['data']['object']['payment_intent']);
        }

        return null;
    }

    private function retrieveOrderIncrementIdFromPaymentIntent($paymentIntentId)
    {
        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        if (!empty($paymentIntent->metadata->{"Order #"})) {
            return $paymentIntent->metadata->{"Order #"};
        }

        return null;
    }

    public function loadOrderFromEvent(?array $event, $includeMultishipping = false)
    {
        if (!is_array($event) || empty($event['id']))
            throw new WebhookException(__("Received invalid request payload."), 400);

        $orderId = $this->getOrderIdFromObject($event['data']['object'], $includeMultishipping);

        if (empty($orderId))
        {
            $orderId = $this->getOrderIdFromLatestEventObject($event);
        }

        if (empty($orderId))
        {
            throw new MissingOrderException(__("Received %1 webhook but there was no associated Order #", $event['type']), 202);
        }

        $this->recordOrderIdAgainstEvent($event['id'], $orderId);

        if (is_array($orderId))
        {
            if (!$includeMultishipping)
                throw new WebhookException(__("This is a multi-shipping event that has not been implemented; ignoring."), 202);

            $orders = [];
            $orderIds = $orderId;
            foreach ($orderIds as $orderId)
                $orders[] = $this->loadWebhookOrderByIncrementId($orderId, $event);

            return $orders;
        }
        else
        {
            if ($includeMultishipping)
            {
                return [ $this->loadWebhookOrderByIncrementId($orderId, $event) ];
            }
            else
            {
                return $this->loadWebhookOrderByIncrementId($orderId, $event);
            }
        }
    }

    public function initStripeFrom($order, $event)
    {
        $paymentMethodCode = $order->getPayment()->getMethod();
        $orderId = $order->getIncrementId();
        if (strpos($paymentMethodCode, "stripe") !== 0)
            throw new WebhookException("Order #$orderId was not placed using Stripe", 202);

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        if (isset($event['data']['object']['livemode']))
            $mode = ($event['data']['object']['livemode'] ? "live" : "test");
        else
            $mode = null;
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
        $this->webhookCollection->pong($this->config->getPublishableKey($mode));
    }

    protected function loadWebhookOrderByIncrementId($orderId, $event)
    {
        if (empty($orderId))
            throw new WebhookException(__("Ignoring %1 webhook event with no associated order ID.", $event['type']), 202);

        $order = $this->orderHelper->loadOrderByIncrementId($orderId);

        if (empty($order) || empty($order->getId()))
        {
            $newOrderId = $this->getOrderIdFromLatestEventObject($event);
            if ($newOrderId && $newOrderId != $orderId)
            {
                $order = $this->orderHelper->loadOrderByIncrementId($newOrderId);
                if ($order && $order->getId())
                {
                    $this->recordOrderIdAgainstEvent($event['id'], $newOrderId);
                }
            }
        }

        if (empty($order) || empty($order->getId()))
        {
            throw new OrderNotFoundException(__("Received %1 webhook with Order #%2 but could not find the order in Magento.", $event['type'], $orderId), 202);
        }

        // Checkout holds stripe_order_{quoteId} for the full QuoteManagement::submit()
        // (including order email). If still held, queue the event — Magento sales resources
        // write every column on update and would clobber send_email/email_sent. Checkout
        // processes the queued event immediately after releasing the lock.
        $this->orderHelper->ensureOrderPlacementComplete($order->getQuoteId());

        // Re-load after the barrier so we never keep a snapshot taken while placement ran.
        $freshOrder = $this->orderHelper->loadOrderByIncrementId($order->getIncrementId(), false);
        if (!empty($freshOrder) && $freshOrder->getId())
        {
            $order = $freshOrder;
        }

        $this->initStripeFrom($order, $event);

        return $order;
    }

    public function sendRecurringOrderFailedEmail($eventArray, $exception)
    {
        try
        {
            $generalName = $this->emailHelper->getName('general');
            $generalEmail = $this->emailHelper->getEmail('general');

            if ($eventArray['livemode'])
                $mode = '';
            else
                $mode = 'test/';

            $object = $eventArray['data']['object'];

            $paymentIntentId = $this->invoicePaymentsHelper->getLatestPaymentIntentIdFromInvoiceId($object["id"]);
            $subscriptionId = $object["parent"]["subscription_details"]["subscription"];

            $templateVars = [
                'paymentLink' => "https://dashboard.stripe.com/{$mode}payments/" . $paymentIntentId,
                'subscriptionLink' => "https://dashboard.stripe.com/{$mode}subscriptions/" . $subscriptionId,
                'customerLink' => "https://dashboard.stripe.com/{$mode}customers/" . $object["customer"],
                'errorMessage' => $exception->getMessage(),
                'stackTrace' => $exception->getTraceAsString(),
                'eventLink' => "https://dashboard.stripe.com/{$mode}events/" . $eventArray["id"]
            ];

            $sent = $this->emailHelper->send('stripe_failed_recurring_order', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);

            if (!$sent)
            {
                $this->helper->logError($exception->getMessage(), $exception->getTraceAsString());
            }
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            $this->helper->logError($exception->getMessage(), $exception->getTraceAsString());
        }
    }

    public function detectRaceCondition($orderIncrementId, $clashingEventTypes)
    {
        $count = $this->webhookEventCollectionFactory->create()->getProcessingEventsCount($orderIncrementId, $clashingEventTypes);

        if ($count > 0)
        {
            throw new RetryLaterException("Race condition detected. Another webhook event is mutating data on the same order. Try again shortly.");
        }
    }

    public function setPaymentDescriptionAfterSubscriptionUpdate($order, \Stripe\Invoice $invoice)
    {
        if (empty($invoice->billing_reason))
            return;

        if ($invoice->billing_reason != "subscription_update")
            return;

        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->invoicePaymentsHelper->getLatestPaymentIntentFromInvoiceId($invoice->id);
        if (empty($paymentIntent->id))
            return;

        if (!empty($paymentIntent->description) && $paymentIntent->description != "Subscription update")
            return;

        $description = $this->orderHelper->getOrderDescription($order);

        try
        {
            $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, [
                'description' => $description
            ]);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function setSubscriptionStatusWhenCustomerUpdate($subscriptionId, $subscriptionStatus)
    {
        $subscriptionModel = $this->subscriptionsHelper->loadSubscriptionModelBySubscriptionId($subscriptionId);
        if ($subscriptionModel)
        {
            $subscriptionModel->setStatus($subscriptionStatus);
            $subscriptionModel->save();
        }
    }

    public function getValidWebhookUrl($store)
    {
        try
        {
            $url = $this->getWebhookUrl($store);
            if ($this->isValidUrl($url))
            {
                return $url;
            }
        }
        catch (\Exception $e)
        {
            return null;
        }

        return null;
    }

    public function getWebhookUrl($store)
    {
        $url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);

        if (empty($url))
        {
            return null;
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);
        $url = rtrim(trim($url), "/");
        $url .= '/stripe/webhooks';
        return $url;
    }

    protected function isValidUrl($url)
    {
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL) === false)
            return false;

        return true;
    }

    public function setDebug(bool $value)
    {
        $this->debug = $value;
    }

    public function wasCapturedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_captured_" . $object['id']))
        {
            return true;
        }

        if (!empty($object['payment_intent']) && is_string($object['payment_intent']) && $this->cache->load("admin_captured_" . $object['payment_intent']))
        {
            return true;
        }

        return false;
    }

    public function addOrderComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        $this->orderHelper->saveOrder($order);
    }

    public function deduplicatePaymentMethod($object)
    {
        try
        {
            if (!empty($object['customer']))
            {
                $type = $object['type'];
                if (!empty($object[$type]['fingerprint']))
                {
                    $this->helper->deduplicatePaymentMethod(
                        $object['customer'],
                        $object['id'],
                        $type,
                        $object[$type]['fingerprint'],
                        $this->config->getStripeClient()
                    );
                }
            }
        }
        catch (\Exception $e)
        {
            return false;
        }

        return true;
    }

    public function wasRefundedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_refunded_" . $object['id']))
            return true;

        return false;
    }

    public function wasReviewedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_reviewed_" . $object['id']))
            return true;

        return false;
    }
}
