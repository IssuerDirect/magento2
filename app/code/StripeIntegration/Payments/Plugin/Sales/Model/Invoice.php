<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model;

use StripeIntegration\Payments\Helper\Radar;

class Invoice
{
    private $radarHelper;

    public function __construct(
        Radar $radarHelper
    )
    {
        $this->radarHelper = $radarHelper;
    }

    public function afterCanCancel($subject, $result)
    {
        return $this->radarHelper->resolveManualReviewActionPermission($subject->getOrder(), $result);
    }

    public function afterCanCapture($subject, $result)
    {
        return $this->radarHelper->resolveManualReviewActionPermission($subject->getOrder(), $result);
    }
    public function afterCanVoid($subject, $result)
    {
        return $this->radarHelper->resolveManualReviewActionPermission($subject->getOrder(), $result);
    }
}
