<?php

namespace StripeIntegration\Payments\Exception;

/**
 * Thrown when a webhook arrives while Magento is still inside quote submit
 * (order place + order email). The event is kept in stripe_webhook_events and
 * processed immediately after checkout releases the placement lock.
 */
class OrderPlacementInProgressException extends WebhookException
{
}
