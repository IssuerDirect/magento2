<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Subscription;

class Flow
{
    public $isRecurringOrderCreated = false;
    public $isSubscriptionChanged = false;
    public $isSubscriptionShipped = false;
    public $isSubscriptionRefunded = false;
}