<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

// See \Magento\Framework\Stdlib\DateTime
class DateTime
{
    public function dbTimestamp()
    {
        $dateTime = new \DateTime();
        return $dateTime->getTimestamp();
    }
}